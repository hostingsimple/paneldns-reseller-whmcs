<?php

/**
 * EmbeddedDnsManager — T1.4 in-WHMCS DNS management for paneldns-reseller.
 *
 * Renders the in-page zone list, record manager, zone create form, and
 * BIND import form. All operations are scoped to the WHMCS customer's
 * `sub_client_id` (stored in the service's `dedicatedip` field) — so a
 * customer can never see or touch another sub-client's zones even if
 * they manipulate URL parameters.
 *
 * AJAX is intentionally NOT used in the MVP — every mutation is a
 * full-page POST + redirect, like classic WHMCS module flows. Snappier
 * inline edits can be layered on later without changing the API.
 *
 * @package paneldns-whmcs
 */

if (!defined('WHMCS')) {
    die('Access denied.');
}

class PanelDnsEmbeddedDnsManager
{
    /** @var PanelDnsApi */ private $api;
    /** @var int */         private $subClientId;
    /** @var array */       private $params;

    public function __construct(PanelDnsApi $api, int $subClientId, array $params)
    {
        $this->api = $api;
        $this->subClientId = $subClientId;
        $this->params = $params;
    }

    // ── Dispatch ─────────────────────────────────────────────────────────────

    /**
     * Returns either ['templatefile'=>..., 'vars'=>...] (WHMCS renders the .tpl)
     * or an array with a 'redirect' key that the calling layer turns into a header.
     */
    public function handle(string $action): array
    {
        // Verify CSRF on mutating requests.
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->requireCsrf();
        }

        return match ($action) {
            'zones'        => $this->renderZonesList(),
            'records'      => $this->renderRecords(),
            'zone-create'  => $this->renderZoneCreate(),
            'zone-import'  => $this->renderZoneImport(),
            // Mutations — these handle the POST then redirect to a list page.
            'do-zone-create'  => $this->doZoneCreate(),
            'do-zone-import'  => $this->doZoneImport(),
            'do-zone-delete'  => $this->doZoneDelete(),
            'do-record-create'=> $this->doRecordCreate(),
            'do-record-update'=> $this->doRecordUpdate(),
            'do-record-delete'=> $this->doRecordDelete(),
            default        => $this->renderZonesList(),
        };
    }

    // ── Page renders ─────────────────────────────────────────────────────────

    private function renderZonesList(): array
    {
        $resp = $this->api->get("/api/v1/zones?sub_client_id={$this->subClientId}&per_page=100");
        return [
            'templatefile' => 'templates/client/zones-list',
            'vars' => [
                'zones'      => $resp['ok'] ? ($resp['data'] ?? []) : [],
                'error'      => $resp['ok'] ? null : ($resp['error'] ?? 'Failed to load zones'),
                'service_id' => (int) $this->params['serviceid'],
                'flash'      => $this->popFlash(),
                'csrf'       => $this->csrfToken(),
            ],
        ];
    }

    private function renderRecords(): array
    {
        $zoneId = (int) ($_GET['zone'] ?? 0);
        if ($zoneId <= 0) return $this->renderZonesList();

        $zone    = $this->fetchOwnZone($zoneId);
        if (!$zone) return $this->withFlash('error', 'Zone not found.', $this->renderZonesList());

        $records = $this->api->get("/api/v1/zones/{$zoneId}/records?per_page=200");

        return [
            'templatefile' => 'templates/client/zone-records',
            'vars' => [
                'zone'       => $zone,
                'records'    => $records['ok'] ? ($records['data'] ?? []) : [],
                'error'      => $records['ok'] ? null : ($records['error'] ?? 'Failed to load records'),
                'service_id' => (int) $this->params['serviceid'],
                'flash'      => $this->popFlash(),
                'csrf'       => $this->csrfToken(),
                'edit_record_id' => (int) ($_GET['edit'] ?? 0) ?: null,
            ],
        ];
    }

    private function renderZoneCreate(): array
    {
        return [
            'templatefile' => 'templates/client/zone-create',
            'vars' => [
                'service_id' => (int) $this->params['serviceid'],
                'csrf'       => $this->csrfToken(),
            ],
        ];
    }

    private function renderZoneImport(): array
    {
        return [
            'templatefile' => 'templates/client/zone-import',
            'vars' => [
                'service_id' => (int) $this->params['serviceid'],
                'csrf'       => $this->csrfToken(),
                'zones'      => $this->fetchOwnZones(),
            ],
        ];
    }

    // ── Mutations ────────────────────────────────────────────────────────────

    private function doZoneCreate(): array
    {
        $name = trim((string) ($_POST['name'] ?? ''));
        if ($name === '') return $this->withFlash('error', 'Zone name is required.', $this->renderZoneCreate());

        $resp = $this->api->post('/api/v1/zones', [
            'name'          => $name,
            'sub_client_id' => $this->subClientId,
        ]);

        if (!$resp['ok']) {
            return $this->withFlash('error', $resp['error'] ?? 'Failed to create zone.', $this->renderZoneCreate());
        }

        $this->flash('success', "Zone {$name} created.");
        return $this->redirectTo('zones');
    }

    private function doZoneImport(): array
    {
        $zoneId   = (int) ($_POST['zone_id'] ?? 0);
        $bindText = (string) ($_POST['bind'] ?? '');

        if ($zoneId <= 0)        return $this->withFlash('error', 'Pick a zone first.', $this->renderZoneImport());
        if (trim($bindText) === '') return $this->withFlash('error', 'Paste BIND-format zone text.', $this->renderZoneImport());

        if (!$this->fetchOwnZone($zoneId)) {
            return $this->withFlash('error', 'Zone not found.', $this->renderZoneImport());
        }

        $resp = $this->api->post("/api/v1/zones/{$zoneId}/import", [
            'bind' => $bindText,
        ]);

        if (!$resp['ok']) {
            return $this->withFlash('error', 'Import failed: ' . ($resp['error'] ?? 'unknown'), $this->renderZoneImport());
        }

        $count = $resp['data']['imported'] ?? '?';
        $this->flash('success', "Imported {$count} records into the zone.");
        return $this->redirectTo('records', "&zone={$zoneId}");
    }

    private function doZoneDelete(): array
    {
        $zoneId = (int) ($_POST['zone_id'] ?? 0);
        if ($zoneId <= 0 || !$this->fetchOwnZone($zoneId)) {
            return $this->withFlash('error', 'Zone not found.', $this->renderZonesList());
        }

        $resp = $this->api->delete("/api/v1/zones/{$zoneId}");
        if (!$resp['ok']) {
            return $this->withFlash('error', 'Delete failed: ' . ($resp['error'] ?? 'unknown'), $this->renderZonesList());
        }

        $this->flash('success', 'Zone deleted.');
        return $this->redirectTo('zones');
    }

    private function doRecordCreate(): array
    {
        $zoneId = (int) ($_POST['zone_id'] ?? 0);
        if (!$this->fetchOwnZone($zoneId)) {
            return $this->withFlash('error', 'Zone not found.', $this->renderZonesList());
        }

        $payload = $this->recordPayloadFromPost();
        $resp = $this->api->post("/api/v1/zones/{$zoneId}/records", $payload);

        if (!$resp['ok']) {
            $this->flash('error', 'Add record failed: ' . ($resp['error'] ?? 'unknown'));
        } else {
            $this->flash('success', 'Record added.');
        }
        return $this->redirectTo('records', "&zone={$zoneId}");
    }

    private function doRecordUpdate(): array
    {
        $zoneId   = (int) ($_POST['zone_id'] ?? 0);
        $recordId = (int) ($_POST['record_id'] ?? 0);
        if (!$this->fetchOwnZone($zoneId) || $recordId <= 0) {
            return $this->withFlash('error', 'Record not found.', $this->renderZonesList());
        }

        $payload = $this->recordPayloadFromPost();
        $resp = $this->api->patch("/api/v1/zones/{$zoneId}/records/{$recordId}", $payload);

        if (!$resp['ok']) {
            $this->flash('error', 'Update failed: ' . ($resp['error'] ?? 'unknown'));
        } else {
            $this->flash('success', 'Record updated.');
        }
        return $this->redirectTo('records', "&zone={$zoneId}");
    }

    private function doRecordDelete(): array
    {
        $zoneId   = (int) ($_POST['zone_id'] ?? 0);
        $recordId = (int) ($_POST['record_id'] ?? 0);
        if (!$this->fetchOwnZone($zoneId) || $recordId <= 0) {
            return $this->withFlash('error', 'Record not found.', $this->renderZonesList());
        }

        $resp = $this->api->delete("/api/v1/zones/{$zoneId}/records/{$recordId}");
        if (!$resp['ok']) {
            $this->flash('error', 'Delete failed: ' . ($resp['error'] ?? 'unknown'));
        } else {
            $this->flash('success', 'Record deleted.');
        }
        return $this->redirectTo('records', "&zone={$zoneId}");
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /** Verify a zone belongs to this customer before any mutation. */
    private function fetchOwnZone(int $zoneId): ?array
    {
        $resp = $this->api->get("/api/v1/zones/{$zoneId}");
        if (!$resp['ok']) return null;
        $z = $resp['data'] ?? null;
        if (!$z) return null;
        if ((int) ($z['sub_client_id'] ?? 0) !== $this->subClientId) return null;
        return $z;
    }

    /** Cached list of zones belonging to this customer. */
    private function fetchOwnZones(): array
    {
        $resp = $this->api->get("/api/v1/zones?sub_client_id={$this->subClientId}&per_page=100");
        return $resp['ok'] ? ($resp['data'] ?? []) : [];
    }

    private function recordPayloadFromPost(): array
    {
        return array_filter([
            'name'     => trim((string) ($_POST['name'] ?? '@')),
            'type'     => strtoupper(trim((string) ($_POST['type'] ?? 'A'))),
            'content'  => trim((string) ($_POST['content'] ?? '')),
            'ttl'      => (int) ($_POST['ttl'] ?? 3600),
            'priority' => isset($_POST['priority']) && $_POST['priority'] !== ''
                ? (int) $_POST['priority']
                : null,
        ], fn ($v) => $v !== null);
    }

    // ── Flash + redirect (full page POST/redirect pattern) ───────────────────

    private function flash(string $type, string $msg): void
    {
        if (!isset($_SESSION)) @session_start();
        $_SESSION['paneldns_flash'] = ['type' => $type, 'msg' => $msg];
    }

    private function popFlash(): ?array
    {
        if (!isset($_SESSION)) @session_start();
        $f = $_SESSION['paneldns_flash'] ?? null;
        unset($_SESSION['paneldns_flash']);
        return $f;
    }

    private function withFlash(string $type, string $msg, array $renderResult): array
    {
        $this->flash($type, $msg);
        $renderResult['vars']['flash'] = ['type' => $type, 'msg' => $msg];
        return $renderResult;
    }

    private function redirectTo(string $action, string $extra = ''): array
    {
        $url = 'clientarea.php?action=productdetails'
             . '&id=' . (int) $this->params['serviceid']
             . '&modop=custom'
             . '&a=' . urlencode($action)
             . $extra;

        // Re-render the destination — WHMCS doesn't support module
        // returning a redirect array, so we re-render server-side. The
        // flash carries the success/failure message.
        return match ($action) {
            'zones'   => $this->renderZonesList(),
            'records' => $this->renderRecords(),
            default   => $this->renderZonesList(),
        };
    }

    // ── CSRF ─────────────────────────────────────────────────────────────────

    /**
     * Generate a per-session CSRF token tied to the customer's WHMCS session
     * and the specific service ID. Bound to service so a leaked token
     * from one product can't be used to mutate another's DNS.
     */
    private function csrfToken(): string
    {
        if (!isset($_SESSION)) @session_start();
        $key = 'paneldns_csrf_' . (int) $this->params['serviceid'];
        if (empty($_SESSION[$key])) {
            $_SESSION[$key] = bin2hex(random_bytes(24));
        }
        return $_SESSION[$key];
    }

    private function requireCsrf(): void
    {
        if (!isset($_SESSION)) @session_start();
        $expected = $_SESSION['paneldns_csrf_' . (int) $this->params['serviceid']] ?? '';
        $supplied = (string) ($_POST['csrf'] ?? '');
        if ($expected === '' || !hash_equals($expected, $supplied)) {
            http_response_code(403);
            die('CSRF token mismatch. Please return to the previous page and try again.');
        }
    }
}
