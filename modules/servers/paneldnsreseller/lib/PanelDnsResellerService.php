<?php

/**
 * PanelDnsResellerService — mirror of PanelDnsPlatformService for the
 * reseller-tier module. Talks to /api/v1 with a per-user Sanctum token
 * instead of /platform/v1 with PLATFORM_API_KEY.
 *
 * Sub-client ID is stored in the WHMCS service's `dedicatedip` field.
 */

if (!defined('WHMCS')) {
    die('Access denied.');
}

// BUILD-ZIP-01 (v1.9.3): every dependency comes from this module's own lib/. build.php
// copies shared/*.php there. Up to 1.9.2 this required __DIR__ . '/../../../shared/…',
// i.e. modules/shared/, which the release ZIP does not contain — the module fataled on
// load from v1.4.1 on.
require_once __DIR__ . '/PanelDnsApi.php';
require_once __DIR__ . '/LicenceCheck.php';
require_once __DIR__ . '/WelcomeMail.php';
require_once __DIR__ . '/PanelDnsResellerHooks.php'; // grace-marker helpers
require_once __DIR__ . '/EmbeddedDnsManager.php';

class PanelDnsResellerService
{
    /** WHMCS module name (the folder name) — tblproducts.servertype. */
    const MODULE = 'paneldnsreseller';

    /** PanelDNS pages sub-clients at a fixed 50; 100 pages = 5,000 sub-clients. */
    const LIST_ACCOUNTS_MAX_PAGES = 100;

    /** Cache lifetimes (seconds). */
    const TTL_SUMMARY     = 60;
    const TTL_ORG         = 300;
    const TTL_NAMESERVERS = 300;

    /**
     * @internal test seam — when set, called with the WHMCS params instead of building
     * a real API client (which also runs the DNS-based SSRF pre-flight).
     * @var null|\Closure
     */
    public static $apiFactory = null;

    public static function run(string $action, array $params): string
    {
        try {
            return (new self($params))->{$action}();
        } catch (\Throwable $e) {
            // FIX-C2: never surface exception messages to WHMCS return values — they
            // may contain server hostnames, tokens, or SQL fragments.
            if (function_exists('logModuleCall')) {
                logModuleCall(self::MODULE, 'error:' . $action, [], get_class($e) . ': ' . $e->getMessage(), '');
            }
            return 'PanelDNS: provisioning error (see module log).';
        }
    }

    /** @var array */ private $params;
    /** @var PanelDnsResellerApi */ private $api;

    private function __construct(array $params)
    {
        $this->params = $params;
        $this->api    = self::buildApi($params);
    }

    /**
     * The API client for a set of WHMCS server params, after the SSRF pre-flight.
     *
     * @throws \RuntimeException when the hostname resolves to a private/reserved address
     */
    private static function buildApi(array $params): PanelDnsResellerApi
    {
        if (self::$apiFactory !== null) {
            return (self::$apiFactory)($params);
        }

        $hostname = $params['serverhostname'] ?? 'localhost';

        // FIX-H4 / SEC-H7: SSRF pre-flight — reject hostnames that resolve to private/reserved IPs.
        $resolved = gethostbyname($hostname);
        if (
            filter_var($resolved, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false
        ) {
            throw new \RuntimeException('paneldnsreseller: server hostname resolves to a private/reserved IP address.');
        }

        $baseUrl = (!empty($params['serversecure']) ? 'https' : 'http')
            . '://' . $hostname
            . (!empty($params['serverport']) && !in_array((int) $params['serverport'], [80, 443], true)
                ? ':' . (int) $params['serverport'] : '');

        return new PanelDnsResellerApi(
            $baseUrl,
            $params['serveraccesshash'] ?? '',
            PanelDnsResellerApi::MODE_RESELLER,
            (bool) ($params['serversecure'] ?? true)
        );
    }

    /**
     * The PanelDNS sub-client this service is linked to: tblhosting.dedicatedip only.
     *
     * NO-EMAIL-LINK-03 (v1.9.4): up to 1.9.3 a product custom field named
     * `paneldns_sub_client_id` was read FIRST. A product custom field that is not
     * Admin Only can be filled in by the client on the order form, so a client could
     * name any sub-client id in the org and Create, SSO, Suspend and Terminate would act
     * on it — account takeover by id. The module never wrote that field and no docs
     * mention it. Dedicated IP is admin-only in WHMCS and is where the module stores
     * the id PanelDNS issued for this service.
     */
    private function subClientId(): ?int
    {
        $v = $this->params['model']?->dedicatedip ?? null;
        $v = is_scalar($v) ? trim((string) $v) : '';
        if ($v === '' || !ctype_digit($v)) {
            return null;
        }
        $id = (int) $v;
        return $id > 0 ? $id : null;
    }

    private function setSubClientId(int $id): void
    {
        if (!empty($this->params['serviceid'])) {
            try {
                \WHMCS\Database\Capsule::table('tblhosting')
                    ->where('id', $this->params['serviceid'])
                    ->update(['dedicatedip' => (string) $id]);
            } catch (\Throwable $e) { /* swallow */ }
        }
    }

    // ── Cache helpers ─────────────────────────────────────────────────────────

    private static function cacheGet(string $key)
    {
        try {
            if (class_exists('\\WHMCS\\Cache\\Store')) {
                return (new \WHMCS\Cache\Store)->get($key);
            }
        } catch (\Throwable $e) { /* fall through to a live call */ }
        return null;
    }

    private static function cachePut(string $key, $value, int $ttl): void
    {
        try {
            if (class_exists('\\WHMCS\\Cache\\Store')) {
                (new \WHMCS\Cache\Store)->put($key, $value, $ttl);
            }
        } catch (\Throwable $e) { /* swallow — the next call goes live */ }
    }

    /**
     * RATE-CALLS-01: GET /api/v1/summary, cached per server+token. The org's slug,
     * portal link and id change rarely; the welcome email, resend and duplicate-email
     * paths all need it.
     */
    private function orgSummary(): ?array
    {
        $key = 'paneldns-org-summary:' . $this->api->identityHash();
        $cached = self::cacheGet($key);
        if (is_array($cached)) {
            return $cached;
        }
        $sum = $this->api->summary();
        if (!$sum['ok'] || !is_array($sum['data'])) {
            return null;
        }
        self::cachePut($key, $sum['data'], self::TTL_ORG);
        return $sum['data'];
    }

    /**
     * Resolve the nameserver list for a product/service.
     * Prefers per-product NS overrides (configoption4-7); falls back to the org's
     * configured nameservers from /api/v1/org/nameservers, cached 5 minutes per
     * server+token (RATE-CALLS-01: the overview page used to call it on every view).
     *
     * Shared by the overview, the welcome email and the embedded record manager, so all
     * three show the same (white-label) hostnames.
     *
     * @return string[] e.g. ['ns1.example.com', 'ns2.example.com']
     */
    public static function nameserversFor(PanelDnsResellerApi $api, array $params): array
    {
        $overrides = array_values(array_filter([
            trim((string) ($params['configoption4'] ?? '')),
            trim((string) ($params['configoption5'] ?? '')),
            trim((string) ($params['configoption6'] ?? '')),
            trim((string) ($params['configoption7'] ?? '')),
        ], fn ($v) => $v !== ''));

        if (!empty($overrides)) return $overrides;

        $key = 'paneldns-org-ns:' . $api->identityHash();
        $cached = self::cacheGet($key);
        if (is_array($cached)) {
            return $cached;
        }

        $resp = $api->nameservers();
        if (!$resp['ok']) {
            return []; // not cached — try again next time
        }
        $ns = array_values(array_filter(
            array_map('strval', (array) ($resp['data']['nameservers'] ?? [])),
            fn ($v) => $v !== ''
        ));
        self::cachePut($key, $ns, self::TTL_NAMESERVERS);
        return $ns;
    }

    private function resolveNameservers(): array
    {
        return self::nameserversFor($this->api, $this->params);
    }

    /**
     * NS-NOTES-01: write the nameservers for this product into WHMCS service notes.
     *
     * Gives support staff instant visibility of "point this domain here" without
     * needing to log into PanelDNS. Best-effort: failures are swallowed so a
     * notes-write failure never blocks provisioning.
     */
    private function writeNameserversToServiceNotes(): void
    {
        $serviceId = (int) ($this->params['serviceid'] ?? 0);
        if ($serviceId <= 0) return;

        $ns = $this->resolveNameservers();
        if (empty($ns)) return;

        $note = 'PanelDNS Nameservers:' . PHP_EOL . implode(PHP_EOL, $ns);
        try {
            \WHMCS\Database\Capsule::table('tblhosting')
                ->where('id', $serviceId)
                ->update(['notes' => $note]);
        } catch (\Throwable $e) { /* swallow — best-effort */ }
    }

    /** Zone + record limits for this order (Configurable Options win — CONF-01). */
    private function limits(): array
    {
        // CONF-01: WHMCS Configurable Options (per-order) take precedence over
        // product-level config options so resellers can offer tiered plans at checkout.
        // Set up a Configurable Option named exactly "Zone Limit" on the product to
        // let customers choose at order time.
        $zoneLimit  = !empty($this->params['configoptions']['Zone Limit'])
            ? (int) $this->params['configoptions']['Zone Limit']
            : (int) ($this->params['configoption1'] ?? 5);
        $maxRecords = !empty($this->params['configoptions']['Max Records Per Zone'])
            ? (int) $this->params['configoptions']['Max Records Per Zone']
            : (int) ($this->params['configoption2'] ?? 100);

        // ADDON-ZONES-01 (v1.9.6): plus every active "PanelDNS +N Zones" addon on this
        // service, so CreateAccount, a package upgrade and an addon change all set the same
        // limit. Up to 1.9.5 ChangePackage reset the limit to the product value and wiped
        // the addon grants. 0 means "inherit the org plan" (no per-sub-client cap) and
        // stays 0: adding a pack to it would impose a cap.
        if ($zoneLimit > 0) {
            $zoneLimit += PanelDnsResellerHooks::addonZones((int) ($this->params['serviceid'] ?? 0));
        }

        // PanelDNS validates zone_limit 0–10,000 and max_records 0–100,000.
        return [
            'zone_limit'  => max(0, min(10000, $zoneLimit)),
            'max_records' => max(0, min(100000, $maxRecords)),
        ];
    }

    /**
     * WHMCS stores a ticked yesno module option as 'on' ('' when unticked); the default
     * from ConfigOptions() is 'yes'. WELCOME-01 (v1.9.3): the welcome-email check
     * compared against 'yes' only, so a product saved with the box ticked ('on') never
     * sent the email.
     */
    private static function yes($value, bool $default): bool
    {
        if ($value === null) return $default;
        return PanelDnsResellerHooks::isYes($value);
    }

    /**
     * POST /api/v1/sub-clients payload. Pure — exposed for unit tests.
     *
     * @internal
     */
    public static function createPayload(array $clientsDetails, string $password, array $limits, string $legalVersion): array
    {
        $email = trim((string) ($clientsDetails['email'] ?? ''));
        $name  = trim(($clientsDetails['firstname'] ?? '') . ' ' . ($clientsDetails['lastname'] ?? ''));
        if ($name === '') $name = $email !== '' ? $email : 'unknown';

        $payload = [
            // PanelDNS validates name max:128.
            'name'               => mb_substr($name, 0, 128),
            'email'              => $email,
            'zone_limit'         => $limits['zone_limit'],
            'max_records'        => $limits['max_records'],
            'status'             => 'active',
            'terms_acknowledged' => true,
        ];

        // PASSWORD-01 (v1.9.3): PanelDNS requires 12–255 characters. A shorter WHMCS
        // service password made CreateAccount fail with a 422; omit it instead and the
        // sub-client signs in through SSO (or sets one with the portal's reset flow).
        $len = strlen($password);
        if ($len >= 12 && $len <= 255) {
            $payload['password'] = $password;
        }

        if ($legalVersion !== '') {
            $payload['terms_version'] = $legalVersion;
        }
        return $payload;
    }

    public function createAccount(): string
    {
        // P2.1: bundled-with-PanelDNS-subscription licence check. Only gates
        // CreateAccount — existing services keep working even if the
        // reseller's PanelDNS subscription lapses (so their customers don't
        // lose DNS overnight). 7-day grace for past_due.
        if ($err = PanelDnsResellerLicenceCheck::gateOrError($this->api, PanelDnsResellerLicenceCheck::REQUIRED_MODULE_RESELLER)) {
            return $err;
        }

        $limits = $this->limits();

        if ($existing = $this->subClientId()) {
            // Already linked (a re-run of Create, or an admin-entered sub-client id):
            // re-activate it with this product's limits.
            $resp = $this->api->patchSubClient($existing, ['status' => 'active'] + $limits);
            if ($resp['ok']) return 'success';
            if ((int) $resp['status'] !== 404) {
                return $resp['error'] ?: 'Re-activating the existing sub-client failed.';
            }
            // CREATE-STALE-01: the stored id no longer exists in this org — provision anew.
            if (function_exists('logModuleCall')) {
                logModuleCall(self::MODULE, 'createAccount:stale-id', ['sub_client_id' => $existing], 'not found upstream; creating a new sub-client', '');
            }
        }

        // GDPR-LEGAL-01: stamp consent at provisioning time so WHMCS-created
        // sub-clients are covered from the moment of account creation. Soft-fail: an
        // empty version just omits terms_version.
        $legalVersion = $this->api->getLegalVersion();

        $clientsDetails = (array) ($this->params['clientsdetails'] ?? []);
        $payload = self::createPayload($clientsDetails, (string) ($this->params['password'] ?? ''), $limits, $legalVersion);

        $resp = $this->api->createSubClient($payload);

        if (!$resp['ok']) {
            // DUP-EMAIL-01: PanelDNS requires the email to be unique per org; a second
            // service for the same client gets 422 {errors: {email: ["… already been taken."]}}.
            // NO-EMAIL-LINK-02 (v1.9.4): this always fails provisioning — it never links.
            if ((int) $resp['status'] === 422 && isset($resp['errors']['email']) && $payload['email'] !== '') {
                return $this->handleDuplicateEmail($payload['email'], (string) $resp['error']);
            }
            return $resp['error'] ?: 'CreateSubClient failed.';
        }

        $newId = (int) ($resp['data']['id'] ?? 0);
        if ($newId <= 0) return 'CreateSubClient succeeded but no id returned.';

        $this->setSubClientId($newId);
        $this->afterProvision($newId);
        return 'success';
    }

    /** Service notes + welcome email, after a sub-client is created or re-linked. */
    private function afterProvision(int $subClientId): void
    {
        // NS-NOTES-01: write the assigned nameservers into the WHMCS service
        // notes so support staff can see them without logging into PanelDNS.
        $this->writeNameserversToServiceNotes();

        if (self::yes($this->params['configoption3'] ?? null, true)) {
            $this->sendWelcomeEmail($subClientId);
        }
    }

    /**
     * DUP-EMAIL-01: CreateAccount got "email already taken". Explain it to the admin.
     *
     * NO-EMAIL-LINK-02 (v1.9.4): this never links, re-activates or otherwise adopts the
     * existing sub-client — it only returns a message, and CreateAccount fails. WHMCS
     * does not verify client email addresses, so an email match proves nothing about
     * who owns the sub-client. Up to 1.9.3 a match was linked automatically when the
     * sub-client's id sat on a terminated service of the same WHMCS client; that id
     * could itself have been linked by email (Bulk Sync, NO-EMAIL-LINK-01), so a
     * mis-link the admin had cancelled came straight back on the next order. The
     * lookup below only finds the candidate so the message can name it; linking is a
     * per-service admin step (enter the id in Dedicated IP and run Create).
     */
    private function handleDuplicateEmail(string $email, string $originalError): string
    {
        $found = $this->api->findSubClientByEmail($email);
        if (!$found['ok']) {
            return 'PanelDNS already has a sub-client with this email, but looking it up failed: '
                . ($found['error'] ?? 'unknown error');
        }
        $sc = $found['sub_client'];
        if ($sc === null) {
            return $originalError; // the 422 was about something else (e.g. a malformed address)
        }
        $scId = (int) ($sc['id'] ?? 0);
        if ($scId <= 0) {
            return 'PanelDNS already has a sub-client with this email, but returned no id for it.';
        }

        // The list is already scoped to the token's org; check the org id as well so a
        // sub-client from another org can never be linked.
        $org   = $this->orgSummary();
        $orgId = (int) ($org['org']['id'] ?? 0);
        if ($orgId <= 0 || (int) ($sc['org_id'] ?? 0) !== $orgId) {
            return "PanelDNS sub-client #{$scId} has this email but could not be confirmed as belonging to "
                . "this server's organisation. It was not linked.";
        }

        try {
            $links = self::linkedServices($scId, (int) ($this->params['serviceid'] ?? 0), (string) ($this->params['serverhostname'] ?? ''));
        } catch (\Throwable $e) {
            if (function_exists('logModuleCall')) {
                logModuleCall(self::MODULE, 'createAccount:dup-email:db', [], get_class($e), '');
            }
            return "PanelDNS sub-client #{$scId} already has this email; checking which WHMCS service uses it failed (see module log). Not linked.";
        }

        $userId = (int) ($this->params['userid']
            ?? $this->params['clientsdetails']['userid']
            ?? $this->params['clientsdetails']['id']
            ?? 0);

        return self::duplicateEmailMessage(self::duplicateEmailDecision($links, $userId), $scId);
    }

    /**
     * Other paneldnsreseller services (on the same PanelDNS host) whose dedicatedip is
     * this sub-client id.
     *
     * @return array<int,object> rows with ->id, ->userid, ->domainstatus, ->notes
     */
    private static function linkedServices(int $subClientId, int $excludeServiceId, string $hostname): array
    {
        $q = \WHMCS\Database\Capsule::table('tblhosting')
            ->join('tblproducts', 'tblhosting.packageid', '=', 'tblproducts.id')
            ->join('tblservers', 'tblhosting.server', '=', 'tblservers.id')
            ->whereIn('tblproducts.servertype', PanelDnsResellerHooks::SERVERTYPES)
            ->where('tblhosting.dedicatedip', (string) $subClientId)
            ->where('tblhosting.id', '!=', $excludeServiceId);
        if ($hostname !== '') {
            $q = $q->where('tblservers.hostname', $hostname);
        }
        return $q->select(['tblhosting.id', 'tblhosting.userid', 'tblhosting.domainstatus', 'tblhosting.notes'])
            ->get()
            ->all();
    }

    /**
     * Pure classification for DUP-EMAIL-01 — which message handleDuplicateEmail() shows.
     * NO-EMAIL-LINK-02 (v1.9.4): only a reason; nothing here can lead to a link.
     *
     * @param array<int,object|array> $links  other services linked to the sub-client
     * @return array{reason:string, service_id:?int}
     * @internal public for unit tests.
     */
    public static function duplicateEmailDecision(array $links, int $currentUserId): array
    {
        $ended = ['Terminated', 'Cancelled', 'Fraud'];
        $sameClient = fn (object $l): bool => $currentUserId > 0 && (int) $l->userid === $currentUserId;

        foreach ($links as $l) {
            $l = (object) $l;
            if (!in_array((string) $l->domainstatus, $ended, true)) {
                return [
                    'reason'     => $sameClient($l) ? 'in_use_same_client' : 'in_use_other_client',
                    'service_id' => (int) $l->id,
                ];
            }
        }
        foreach ($links as $l) {
            $l = (object) $l;
            if (!$sameClient($l)) {
                return ['reason' => 'other_client', 'service_id' => (int) $l->id];
            }
        }

        if ($links === []) {
            return ['reason' => 'unlinked', 'service_id' => null];
        }
        $first = (object) reset($links);
        return ['reason' => 'same_client_terminated', 'service_id' => (int) $first->id];
    }

    /** @internal public for unit tests. */
    public static function duplicateEmailMessage(array $decision, int $subClientId): string
    {
        $svc = (int) ($decision['service_id'] ?? 0);
        $notLinked = 'It was not linked automatically, because WHMCS does not verify client email addresses and a matching email does not prove this client owns it.';
        $manual = "If you have confirmed this client owns it, enter {$subClientId} in this service's Dedicated IP field and run Create again.";
        return match ($decision['reason']) {
            'in_use_same_client' => "This client already has a PanelDNS account (sub-client #{$subClientId}) on service #{$svc}. "
                . 'PanelDNS allows one sub-client per email address in an organisation, so this second service cannot get its own. '
                . "Upgrade service #{$svc} instead, or cancel this order.",
            'in_use_other_client' => "PanelDNS sub-client #{$subClientId} has this email and is linked to service #{$svc} of another WHMCS client. "
                . 'It was not linked. Resolve the duplicate client email in WHMCS first.',
            'same_client_terminated' => "PanelDNS sub-client #{$subClientId} has this email and was used by this client's terminated service #{$svc}. "
                . $notLinked . ' ' . $manual,
            'other_client' => "PanelDNS sub-client #{$subClientId} has this email but was last used by service #{$svc} of another WHMCS client. "
                . $notLinked . ' ' . $manual,
            default => "PanelDNS already has a sub-client with this email (#{$subClientId}) and no WHMCS service is linked to it. "
                . $notLinked . ' ' . $manual,
        };
    }

    public function suspendAccount(): string
    {
        $id = $this->subClientId();
        if (!$id) return 'No sub-client id.';
        $resp = $this->api->patchSubClient($id, ['status' => 'suspended']);
        return $resp['ok'] ? 'success' : ($resp['error'] ?: 'Suspend failed.');
    }

    public function unsuspendAccount(): string
    {
        $id = $this->subClientId();
        if (!$id) return 'No sub-client id.';
        $resp = $this->api->patchSubClient($id, ['status' => 'active']);
        return $resp['ok'] ? 'success' : ($resp['error'] ?: 'Unsuspend failed.');
    }

    public function terminateAccount(): string
    {
        $id = $this->subClientId();
        if (!$id) return 'success';

        // FIX-L4: clamp grace period to prevent absurdly large values (e.g. 9999 days).
        $graceDays = min(365, max(0, (int) ($this->params['configoption11'] ?? 0)));

        if ($graceDays > 0) {
            // GRACE-01: suspend instead of delete. DailyCronJob processes the
            // actual deletion once the deadline passes. Terminated WHMCS services
            // are excluded from PanelDnsResellerDriftSync so the suspension is not reversed.
            // TERM-ZONES-01: the zones are deleted with the sub-client when the period
            // ends. Until then they keep resolving: the reseller API has no call that
            // takes a sub-client's zones offline without deleting them.
            $resp = $this->api->patchSubClient($id, ['status' => 'suspended']);
            if (!$resp['ok']) return $resp['error'] ?: 'Grace-period suspend failed.';

            $deadline  = date('Y-m-d', strtotime("+{$graceDays} days"));
            $serviceId = (int) ($this->params['serviceid'] ?? 0);
            try {
                $notes = (string) \WHMCS\Database\Capsule::table('tblhosting')->where('id', $serviceId)->value('notes');
                // GRACE-05 (v1.9.3): append the marker instead of overwriting the notes.
                \WHMCS\Database\Capsule::table('tblhosting')
                    ->where('id', $serviceId)
                    ->update(['notes' => PanelDnsResellerHooks::withGraceMarker($notes, $deadline)]);
            } catch (\Throwable $e) { /* swallow — grace still applied in PanelDNS */ }
            return 'success';
        }

        // TERM-ZONES-01 (v1.9.6): delete the sub-client's zones first. PanelDNS deletes only
        // the sub-client row and keeps its zones live as org-level zones. On a failure the
        // sub-client is kept, so running Terminate again picks up where this stopped.
        $zones = PanelDnsResellerHooks::deleteSubClientZones($this->api, $id);
        if (!$zones['ok']) {
            return "PanelDNS: sub-client #{$id} was not deleted because {$zones['error']}"
                . ($zones['deleted'] > 0 ? " ({$zones['deleted']} zone(s) were deleted)" : '')
                . '. Run Terminate again.';
        }

        $resp = $this->api->deleteSubClient($id);
        if ($resp['ok'] || (int) $resp['status'] === 404) return 'success'; // 404: already gone
        return $resp['error'] ?: 'Terminate failed.';
    }

    public function changePackage(): string
    {
        $id = $this->subClientId();
        if (!$id) return 'No sub-client id.';

        $resp = $this->api->patchSubClient($id, $this->limits());
        return $resp['ok'] ? 'success' : ($resp['error'] ?: 'ChangePackage failed.');
    }

    public function testConnection(): string
    {
        $sum = $this->api->summary();
        if ($sum['ok']) return 'success';
        // FIX-M11/FIX-M2: log the real error but return a generic message to WHMCS UI.
        if (function_exists('logModuleCall')) {
            logModuleCall(self::MODULE, 'testConnection:fail', [], $sum['error'] ?? '', '');
        }
        if ((int) $sum['status'] === 429) {
            return (string) $sum['error'];
        }
        return 'Authentication failed. Check the module log for details.';
    }

    public function resyncStatus(): string
    {
        $id = $this->subClientId();
        if (!$id) return 'No sub-client id.';
        $resp = $this->api->subClientSummary($id);
        if ($resp['ok']) {
            self::cachePut('paneldns-sub-summary:' . $id, null, 1); // drop the admin-tab cache
        }
        // SEC-H8: never return raw API error strings — already logged by PanelDnsResellerApi::logCall()
        return $resp['ok'] ? 'success' : 'Resync failed. See module log for details.';
    }

    /**
     * ServiceSingleSignOn / client SSO: mint a portal token and return a redirect URL.
     * Called by WHMCS when the client clicks "Login to PanelDNS" on their service page,
     * and also by the clientArea() 'sso' action for the in-template portal button.
     *
     * @return array{success: bool, redirectTo?: string, errorMsg?: string}
     */
    public static function clientSso(array $params): array
    {
        try {
            $svc = new self($params);
            $id  = $svc->subClientId();
            if (!$id) {
                return ['success' => false, 'errorMsg' => 'Service not provisioned yet.'];
            }
            $resp = $svc->api->mintSubClientSsoToken($id);
            if (!$resp['ok'] || empty($resp['data']['login_url'])) {
                return ['success' => false, 'errorMsg' => $resp['error'] ?? 'SSO token mint failed.'];
            }
            // SSO-REDIRECT-01 (v1.9.3): WHMCS reads the target from 'redirectTo'
            // (developers.whmcs.com/provisioning-modules/single-sign-on/). Up to 1.9.2 this
            // returned 'redirectUrl', so the Login buttons never redirected.
            return ['success' => true, 'redirectTo' => (string) $resp['data']['login_url']];
        } catch (\Throwable $e) {
            // FIX-C2: redact exception detail from SSO error message.
            if (function_exists('logModuleCall')) {
                logModuleCall(self::MODULE, 'error:clientSso', [], get_class($e) . ': ' . $e->getMessage(), '');
            }
            return ['success' => false, 'errorMsg' => 'PanelDNS: SSO error (see module log).'];
        }
    }

    /**
     * UsageUpdate: return zone/record counts for WHMCS usage graphs.
     * Zone count maps to "disk" and record count to "bandwidth" — the standard
     * pattern for non-hosting modules without actual disk/bandwidth metrics.
     *
     * @return array{success: bool, diskusage?: int, disklimit?: int, bwusage?: int, bwlimit?: int}
     */
    public static function usageUpdate(array $params): array
    {
        try {
            $svc = new self($params);
            $id  = $svc->subClientId();
            if (!$id) return ['success' => false];

            $resp = $svc->api->subClientSummary($id);
            if (!$resp['ok']) return ['success' => false];

            $usage  = $resp['data']['usage']  ?? [];
            $limits = $resp['data']['limits'] ?? [];

            return [
                'success'   => true,
                'diskusage' => (int) ($usage['zones']   ?? 0),
                'disklimit' => (int) ($limits['zones']  ?? 0),
                'bwusage'   => (int) ($usage['records'] ?? 0),
                'bwlimit'   => (int) ($limits['records'] ?? 0),
            ];
        } catch (\Throwable $e) {
            return ['success' => false];
        }
    }

    /**
     * AdminSingleSignOn — mint a portal SSO token and return a redirect URL.
     * WHMCS calls this when the admin clicks the SSO button on the service page
     * and redirects the admin's browser to the returned URL.
     *
     * @return array{success: bool, redirectTo?: string, errorMsg?: string}
     */
    public static function adminSso(array $params): array
    {
        try {
            $svc = new self($params);
            $id  = $svc->subClientId();
            if (!$id) {
                return ['success' => false, 'errorMsg' => 'Service not provisioned yet.'];
            }

            $resp = $svc->api->mintSubClientSsoToken($id);
            if (!$resp['ok'] || empty($resp['data']['login_url'])) {
                return ['success' => false, 'errorMsg' => $resp['error'] ?? 'SSO token mint failed.'];
            }

            // SSO-REDIRECT-01 (v1.9.3): WHMCS reads the target from 'redirectTo'
            // (developers.whmcs.com/provisioning-modules/single-sign-on/). Up to 1.9.2 this
            // returned 'redirectUrl', so the Login buttons never redirected.
            return ['success' => true, 'redirectTo' => (string) $resp['data']['login_url']];
        } catch (\Throwable $e) {
            // FIX-C2: redact exception detail from admin SSO error message.
            if (function_exists('logModuleCall')) {
                logModuleCall(self::MODULE, 'error:adminSso', [], get_class($e) . ': ' . $e->getMessage(), '');
            }
            return ['success' => false, 'errorMsg' => 'PanelDNS: SSO error (see module log).'];
        }
    }

    /**
     * Resend the welcome email with a fresh 60-second SSO link.
     * Triggered by the "Resend Welcome Email" admin custom button.
     * Uses the same send path as the post-CreateAccount welcome email.
     */
    public function resendWelcome(): string
    {
        $id = $this->subClientId();
        if (!$id) return 'Service not provisioned — cannot send welcome email.';

        $sso = $this->api->mintSubClientSsoToken($id);
        if (!$sso['ok'] || empty($sso['data']['login_url'])) {
            return 'SSO mint failed: ' . ($sso['error'] ?? 'unknown');
        }

        PanelDnsResellerWelcomeMail::sendReseller((int) $this->params['serviceid'], $sso['data'], $this->welcomeContext());
        return 'success';
    }

    /**
     * ListAccounts — return all sub-clients on this server for WHMCS sync.
     *
     * PAGING-01 (v1.9.3): PanelDNS returns a FIXED 50 per page and ignores per_page.
     * This used to ask for 100 and stop on the first page shorter than 100 — i.e.
     * always after page 1, so the Sync tool only ever saw 50 sub-clients. It now
     * follows meta.total, up to LIST_ACCOUNTS_MAX_PAGES (5,000 sub-clients). A failed
     * page is an error rather than a partial list: a partial list makes the Sync tool
     * report live services as missing.
     */
    public static function listAccounts(array $params): array
    {
        try {
            $svc  = new self($params);
            $resp = $svc->api->getAllPages('/api/v1/sub-clients', [], self::LIST_ACCOUNTS_MAX_PAGES);
            if (!$resp['ok']) {
                return ['success' => false, 'error' => 'Could not list PanelDNS sub-clients: ' . ($resp['error'] ?? 'API request failed')];
            }
            if ($resp['truncated'] && function_exists('logModuleCall')) {
                logModuleCall(self::MODULE, 'ListAccounts:truncated', [], 'Returned the first ' . count($resp['data'])
                    . ' of ' . (int) $resp['total'] . ' sub-clients (page cap).', '');
            }

            $accounts = [];
            foreach ($resp['data'] as $sc) {
                $accounts[] = [
                    'username'         => $sc['email'] ?? '',
                    'domain'           => $sc['name']  ?? ($sc['email'] ?? ''),
                    'uniqueIdentifier' => (string) ($sc['id'] ?? ''),
                    'package'          => 'Zones: ' . ($sc['zone_limit'] ?? '?'),
                    'suspended'        => ($sc['status'] ?? '') === 'suspended',
                ];
            }

            return ['success' => true, 'accounts' => $accounts];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => 'ListAccounts failed: ' . get_class($e)];
        }
    }

    /**
     * BULK-01: Bulk-sync all WHMCS services on this server to PanelDNS sub-clients.
     *
     * Called from the server-level "Bulk Sync Sub-clients" custom button. Iterates
     * every Active/Suspended tblhosting row on this WHMCS server and ensures a
     * matching PanelDNS sub-client exists:
     *
     *   1. Already has dedicatedip set → skip (already provisioned; no API call).
     *   2. No dedicatedip → look the client's email up in PanelDNS (exact match, all
     *      result pages). If found → report it for review and leave the service alone.
     *      If not found → create a sub-client and store the id PanelDNS returns.
     *
     * NO-EMAIL-LINK-01 (v1.9.4): an email match is NEVER linked. Up to 1.9.3 a match
     * that no other live service used was written into dedicatedip, so one click
     * linked every unlinked service to whatever sub-client shared its client's email.
     * WHMCS does not verify client emails: a client who set their email to someone
     * else's became that person in PanelDNS (Login to PanelDNS, the welcome-email SSO
     * link, Terminate). A match is now listed under "Needs review" with the service and
     * sub-client ids; linking is a per-service admin step (enter the id in Dedicated IP
     * and run Create), after the admin has confirmed who owns the sub-client.
     *
     * Welcome emails are NOT sent during bulk sync — clients are provisioned
     * silently. The reseller can trigger one per service with Resend Welcome Email.
     *
     * Capped at 200 services per run to prevent PHP timeouts on large installs.
     * Re-run to continue if the cap is hit. Stops early on a PanelDNS rate limit.
     *
     * @param array $params  WHMCS server params (serverhostname, serveraccesshash, …)
     * @return string  Summary line: "Created: N | Needs review (not linked): N | Skipped: N
     *                 [| Review: …] [| Errors: …]"
     */
    public static function bulkSyncForServer(array $params): string
    {
        try {
            // SEC-H7: SSRF pre-flight runs inside buildApi().
            $api = self::buildApi($params);
        } catch (\Throwable $e) {
            return 'Server hostname resolves to a private/reserved IP address.';
        }

        // Verify connectivity before touching the DB.
        $ping = $api->ping();
        if (!$ping['ok']) {
            return 'Connection failed: ' . ($ping['error'] ?? 'unknown error');
        }

        $serverId = (int) ($params['serverid'] ?? 0);
        if ($serverId <= 0) {
            return 'No server ID in params.';
        }
        $hostname = (string) ($params['serverhostname'] ?? '');

        // BULK-02: fetch Active/Suspended services with client + product config in one join.
        try {
            $services = \WHMCS\Database\Capsule::table('tblhosting as h')
                ->join('tblclients as c', 'c.id', '=', 'h.userid')
                ->join('tblproducts as p', 'p.id', '=', 'h.packageid')
                ->where('h.server', $serverId)
                ->whereIn('p.servertype', PanelDnsResellerHooks::SERVERTYPES)
                ->whereIn('h.domainstatus', ['Active', 'Suspended'])
                ->select([
                    'h.id as serviceid',
                    'h.userid',
                    'h.dedicatedip',
                    'h.domainstatus',
                    'c.email',
                    'c.firstname',
                    'c.lastname',
                    'p.configoption1 as zone_limit',
                    'p.configoption2 as max_records',
                ])
                ->get()
                ->all();
        } catch (\Throwable $e) {
            // FIX-C2: redact exception message — may contain SQL fragments.
            if (function_exists('logModuleCall')) {
                logModuleCall(self::MODULE, 'error:bulkSync:db', [], get_class($e) . ': ' . $e->getMessage(), '');
            }
            return 'DB error — see module log for details.';
        }

        if (empty($services)) {
            return 'No active or suspended services found on this server.';
        }

        $cap      = 200; // BULK-03: hard cap — re-run to process remainder
        $created  = 0;
        $skipped  = 0;
        $review   = []; // NO-EMAIL-LINK-01: email matches, reported and never linked
        $errors   = [];
        $processed = 0;

        foreach ($services as $svc) {
            if ($processed >= $cap) {
                $errors[] = "Cap reached ({$cap} services). Re-run to continue.";
                break;
            }
            $processed++;

            // Already provisioned — nothing to do.
            if (!empty($svc->dedicatedip) && is_numeric(trim((string) $svc->dedicatedip))) {
                $skipped++;
                continue;
            }

            $email = trim((string) ($svc->email ?? ''));
            if ($email === '') {
                $errors[] = "Service #{$svc->serviceid}: client has no email address.";
                continue;
            }

            // Search for an existing sub-client with this exact email (all pages).
            $search = $api->findSubClientByEmail($email);
            if (!$search['ok']) {
                if ((int) $search['status'] === 429) {
                    $errors[] = 'Stopped: ' . $search['error'] . ' Re-run to continue.';
                    break;
                }
                $errors[] = "Service #{$svc->serviceid}: lookup failed — " . $search['error'];
                continue;
            }
            $existing = $search['sub_client'];

            if ($existing !== null) {
                // BULK-04 / NO-EMAIL-LINK-01: a sub-client already has this email. Report
                // it; never store its id on the service — the email proves nothing.
                $existingId = (int) ($existing['id'] ?? 0);
                $review[] = self::bulkReviewLine((int) $svc->serviceid, $existingId, $hostname);
                continue;
            }

            // BULK-05: provision — no sub-client exists; create one.
            $name = trim(($svc->firstname ?? '') . ' ' . ($svc->lastname ?? ''));
            if ($name === '') $name = $email;

            $resp = $api->createSubClient([
                'name'        => mb_substr($name, 0, 128),
                'email'       => $email,
                // Random password — sub-client signs in by SSO or resets it in the portal.
                // Welcome email intentionally skipped for bulk operations.
                'password'    => bin2hex(random_bytes(12)),
                'zone_limit'  => max(0, min(10000, (int) ($svc->zone_limit  ?? 5))),
                'max_records' => max(0, min(100000, (int) ($svc->max_records ?? 100))),
                'status'      => $svc->domainstatus === 'Active' ? 'active' : 'suspended',
            ]);

            if (!$resp['ok']) {
                if ((int) $resp['status'] === 429) {
                    $errors[] = 'Stopped: ' . $resp['error'] . ' Re-run to continue.';
                    break;
                }
                $errors[] = "Service #{$svc->serviceid} ({$email}): " . ($resp['error'] ?? 'create failed');
                continue;
            }

            $newId = (int) ($resp['data']['id'] ?? 0);
            if ($newId <= 0) {
                $errors[] = "Service #{$svc->serviceid}: created but no id returned.";
                continue;
            }

            try {
                \WHMCS\Database\Capsule::table('tblhosting')
                    ->where('id', $svc->serviceid)
                    ->update(['dedicatedip' => (string) $newId]);
            } catch (\Throwable $e) {
                $errors[] = "Service #{$svc->serviceid}: ID store failed after create.";
            }

            $created++;
        }

        $summary = "Created: {$created} | Needs review (not linked): " . count($review)
            . " | Skipped (already provisioned): {$skipped}";
        if (!empty($review)) {
            $summary .= ' | Review: ' . implode('; ', array_slice($review, 0, 5));
            if (count($review) > 5) {
                $summary .= ' … (' . count($review) . ' total)';
            }
            $summary .= ". These were NOT linked — a matching email does not prove ownership. To link one after"
                . " confirming the client owns that sub-client, enter its id in the service's Dedicated IP field and run Create";
        }
        if (!empty($errors)) {
            $first5 = array_slice($errors, 0, 5);
            $summary .= ' | Errors: ' . implode('; ', $first5);
            if (count($errors) > 5) {
                $summary .= ' … (' . count($errors) . ' total)';
            }
        }
        return $summary;
    }

    /**
     * NO-EMAIL-LINK-01: one Bulk Sync "Needs review" entry. Informational only — it notes
     * whether another live service already uses the sub-client, and links nothing.
     */
    private static function bulkReviewLine(int $serviceId, int $subClientId, string $hostname): string
    {
        if ($subClientId <= 0) {
            return "service #{$serviceId}: a sub-client has this client's email but PanelDNS returned no id for it";
        }
        $usedBy = '';
        try {
            $live = array_filter(
                self::linkedServices($subClientId, $serviceId, $hostname),
                fn ($o) => !in_array((string) $o->domainstatus, ['Terminated', 'Cancelled', 'Fraud'], true)
            );
            if ($live !== []) {
                $usedBy = ', already used by service #' . (int) reset($live)->id;
            }
        } catch (\Throwable $e) { /* informational only */ }
        return "service #{$serviceId}: sub-client #{$subClientId} has this client's email{$usedBy}";
    }

    /**
     * P2.3: render the admin service-detail panel for the reseller module.
     * Pulls /api/v1/sub-clients/{id}/summary and the first page of zones, cached
     * together for 60 s (RATE-CALLS-01: the zone list was fetched on every render).
     */
    public static function adminServicesTabFields(array $params): array
    {
        try {
            $svc = new self($params);
        } catch (\Throwable $e) {
            return ['PanelDNS' => '<em>Server configuration error (see module log).</em>'];
        }
        $id  = $svc->subClientId();
        if (!$id) {
            return ['PanelDNS' => '<em>Not provisioned yet.</em>'];
        }

        $cacheKey = 'paneldns-sub-summary:' . $id;
        $cached = self::cacheGet($cacheKey);

        if (!is_array($cached) || !isset($cached['summary'])) {
            $resp = $svc->api->subClientSummary($id);
            if (!$resp['ok']) {
                return [
                    'PanelDNS Sub-client ID' => htmlspecialchars((string) $id, ENT_QUOTES, 'UTF-8'),
                    'PanelDNS Status'        => '<span style="color:#c00;">⚠ ' . htmlspecialchars($resp['error'] ?: 'Lookup failed', ENT_QUOTES, 'UTF-8') . '</span>',
                ];
            }

            // ZONE-LIST-01: the first page (PanelDNS returns 50; per_page is ignored)
            // gives the first names plus meta.total for the "and N more" line.
            $names = [];
            $total = 0;
            $zonesResp = $svc->api->get('/api/v1/zones', ['sub_client_id' => $id]);
            if ($zonesResp['ok'] && is_array($zonesResp['data'])) {
                foreach (array_slice($zonesResp['data'], 0, 20) as $z) {
                    $names[] = (string) ($z['name'] ?? '');
                }
                $total = (int) ($zonesResp['meta']['total'] ?? count($zonesResp['data']));
            }

            $cached = ['summary' => $resp['data'], 'zone_names' => $names, 'zones_total' => $total];
            self::cachePut($cacheKey, $cached, self::TTL_SUMMARY);
        }

        $summary  = (array) $cached['summary'];
        $sub      = $summary['sub_client'] ?? [];
        $usage    = $summary['usage']      ?? [];
        $limits   = $summary['limits']     ?? [];
        $status   = $sub['status'] ?? 'unknown';
        $colour   = $status === 'active' ? '#0a7' : ($status === 'suspended' ? '#c80' : '#888');

        $moduleVersion = defined('PANELDNS_RESELLER_MODULE_VERSION')
            ? PANELDNS_RESELLER_MODULE_VERSION : 'unknown';

        // Feature 5 — live per-sub-client usage fields.
        // Zone and record bars show a colour-coded utilisation indicator so
        // the operator can spot near-limit accounts at a glance.
        $zonesUsed  = (int) ($usage['zones']   ?? 0);
        $zonesLimit = (int) ($limits['zones']  ?? 0);
        $recsUsed   = (int) ($usage['records'] ?? 0);
        $recsLimit  = (int) ($limits['records'] ?? 0);

        $zonePct = $zonesLimit > 0 ? (int) min(100, round($zonesUsed * 100 / $zonesLimit)) : 0;
        $recsPct  = $recsLimit > 0  ? (int) min(100, round($recsUsed * 100 / $recsLimit))  : 0;

        $bar = function (int $pct): string {
            return sprintf(
                ' <span style="display:inline-block;width:80px;height:8px;background:#e5e7eb;border-radius:4px;vertical-align:middle;overflow:hidden;">'
                . '<span style="display:block;height:100%%;width:%d%%;background:%s;border-radius:4px;"></span></span>'
                . ' <span style="color:#6b7280;font-size:11px;">%d%%</span>',
                $pct,
                $pct >= 90 ? '#dc2626' : ($pct >= 75 ? '#f59e0b' : '#0891b2'),
                $pct
            );
        };
        $zoneBar = $zonesLimit > 0 ? $bar($zonePct) : '';
        $recsBar = $recsLimit > 0 ? $bar($recsPct) : '';

        // The sub-client resource carries updated_at, not a sync time.
        $updated = $sub['updated_at'] ?? null;
        $updatedDisplay = $updated
            ? htmlspecialchars((string) $updated, ENT_QUOTES, 'UTF-8')
            : '<span style="color:#9ca3af;">—</span>';

        $zoneNames = '<span style="color:#9ca3af;">—</span>';
        $names = array_map(fn ($n) => htmlspecialchars($n, ENT_QUOTES, 'UTF-8'), (array) ($cached['zone_names'] ?? []));
        if ($names !== []) {
            $zoneNames = implode('<br>', $names);
            $more = (int) ($cached['zones_total'] ?? 0) - count($names);
            if ($more > 0) {
                $zoneNames .= '<br><em style="color:#9ca3af;font-size:11px;">… and ' . $more . ' more</em>';
            }
        }

        return [
            'Module version'         => '<span style="color:#6b7280;font-variant-numeric:tabular-nums;">paneldnsreseller v'
                . htmlspecialchars($moduleVersion, ENT_QUOTES, 'UTF-8') . '</span>',
            'PanelDNS Sub-client ID' => '<strong>' . htmlspecialchars((string) $id, ENT_QUOTES, 'UTF-8') . '</strong>',
            'Status'                 => sprintf(
                '<span style="color:%s; font-weight:600; text-transform:capitalize;">%s</span>',
                $colour,
                htmlspecialchars($status, ENT_QUOTES, 'UTF-8')
            ),
            'Sub-client Email'       => htmlspecialchars((string) ($sub['email'] ?? ''), ENT_QUOTES, 'UTF-8'),
            'Zones used / limit'     => sprintf('%d / %s', $zonesUsed, $zonesLimit > 0 ? $zonesLimit : '∞') . $zoneBar,
            'Records used / limit'   => sprintf('%d / %s', $recsUsed, $recsLimit > 0 ? $recsLimit : '∞') . $recsBar,
            'Last updated'           => $updatedDisplay,
            'Zones'                  => $zoneNames,
            'Server'                 => htmlspecialchars(
                ($svc->params['serverhostname'] ?? ''),
                ENT_QUOTES,
                'UTF-8'
            ),
        ];
    }

    /** The embedded DNS manager pages and form targets (customAction values). */
    const EMBEDDED_ACTIONS = [
        'zones', 'records', 'zone-create', 'zone-import',
        'zone-export', // EXPORT-01: BIND download — EmbeddedDnsManager streams and exits
        'do-zone-create', 'do-zone-import', 'do-zone-delete',
        'do-record-create', 'do-record-update', 'do-record-delete',
        // DNSSEC-02 (v1.9.3): the toggle form posted here but the action was missing from
        // this list, so it fell through to the overview and never ran.
        'do-dnssec-toggle',
    ];

    /**
     * CLIENT-ROUTE-01: the requested page. WHMCS does not put request parameters in
     * $params; the embedded pages are selected with ?customAction= (the WHMCS sample
     * module's pattern). $params['customAction'] is still honoured if a caller sets it.
     *
     * @internal public for unit tests.
     */
    public static function requestedAction(array $params, array $request): string
    {
        $a = $params['customAction'] ?? $request['customAction'] ?? '';
        return is_string($a) ? $a : '';
    }

    /**
     * WHMCS-PERM-01 (v1.9.6): the WHMCS user permission that allows a user to open the
     * DNS portal and to use the embedded DNS manager: "Perform Single Sign-On"
     * (`productsso`), the permission WHMCS itself requires for ServiceSingleSignOn. Both
     * give the same power (the portal can do everything the embedded manager does), so
     * one permission gates both.
     */
    const MANAGE_PERMISSION = 'productsso';

    const MSG_NO_PERMISSION = 'Your user account does not have permission to manage DNS for this service.'
        . ' The account owner can grant it under Account → User Management (Perform Single Sign-On).';

    /**
     * WHMCS-PERM-01 (v1.9.6): may the signed-in WHMCS user manage this service's DNS?
     *
     * WHMCS 8 accounts can have several users with per-user permissions. WHMCS checks
     * `productsso` before its own Login button runs ServiceSingleSignOn, but the module's
     * in-template portal button (?customAction=sso) and the embedded DNS manager ran for
     * anyone who could open the product page, so a user invited with view-only access got
     * a full portal login and could delete zones.
     *
     * Checked with the documented WHMCS APIs: \WHMCS\Authentication\CurrentUser user() and
     * client() (https://developers.whmcs.com/advanced/authentication/), Client::isOwnedBy()
     * (https://developers.whmcs.com/classes/whmcs/user/client/) — the owner holds every
     * permission — and for any other user localAPI GetUserPermissions(user_id, client_id)
     * (https://developers.whmcs.com/api-reference/getuserpermissions/), whose permission
     * names are those of GetPermissionsList. Fails closed: no user, another client
     * account, an API error or an unreadable answer all refuse.
     */
    public static function userMayManage(array $params): bool
    {
        $clientId = (int) ($params['userid'] ?? 0);
        if ($clientId <= 0 || !class_exists('\\WHMCS\\Authentication\\CurrentUser')) {
            return false;
        }
        try {
            $current = new \WHMCS\Authentication\CurrentUser();
            $user    = $current->user();
            $client  = $current->client();
            if (!$user || !$client || (int) $client->id !== $clientId) {
                return false;
            }
            if ($client->isOwnedBy($user)) {
                return true;
            }
            $resp = localAPI('GetUserPermissions', ['user_id' => (int) $user->id, 'client_id' => $clientId]);
            if (!is_array($resp) || ($resp['result'] ?? '') !== 'success') {
                return false;
            }
            return in_array(self::MANAGE_PERMISSION, self::permissionNames($resp['permissions'] ?? null), true);
        } catch (\Throwable $e) {
            if (function_exists('logModuleCall')) {
                logModuleCall(self::MODULE, 'error:userMayManage', ['client_id' => $clientId], get_class($e), '');
            }
            return false;
        }
    }

    /**
     * WHMCS-PERM-01: permission names from a GetUserPermissions answer — a list, a
     * comma-separated string, a nested list or a name => true map.
     *
     * @return string[]
     * @internal public for unit tests.
     */
    public static function permissionNames($raw): array
    {
        if (is_string($raw)) {
            $raw = [$raw];
        }
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        array_walk_recursive($raw, function ($v, $k) use (&$out) {
            if (is_string($k) && ($v === true || $v === 1 || $v === '1')) {
                $out[] = strtolower(trim($k));
            } elseif (is_string($v)) {
                foreach (explode(',', $v) as $name) {
                    $out[] = strtolower(trim($name));
                }
            }
        });
        return array_values(array_unique(array_filter($out, fn ($n) => $n !== '')));
    }

    public static function clientArea(array $params): array
    {
        // T1.4: dispatch on customAction to either the embedded DNS
        // manager (zones / records / create / import / mutations) or
        // the legacy "overview" template with usage cards + SSO button.
        $action = self::requestedAction($params, $_REQUEST);

        try {
            $svc = new self($params);
        } catch (\Throwable $e) {
            if (function_exists('logModuleCall')) {
                logModuleCall(self::MODULE, 'error:clientArea', [], get_class($e), '');
            }
            return [
                'templatefile' => 'templates/client/overview',
                'vars' => ['paneldns_error' => 'DNS service is temporarily unavailable.', 'paneldns_nameservers' => []],
            ];
        }

        // SVC-STATUS-01 (v1.9.6): the portal button and the embedded DNS manager only for an
        // Active service. Up to 1.9.5 nothing here looked at the service status: whether a
        // Suspended, Pending or Terminated (grace period) service could edit DNS depended on
        // WHMCS's page rendering alone, and PanelDNS refuses writes through the reseller
        // token only for a suspended ZONE, not a suspended sub-client. A missing status
        // refuses too. WHMCS passes the service status as $params['status'].
        $serviceStatus = is_string($params['status'] ?? null) ? $params['status'] : '';
        $active        = $serviceStatus === 'Active';

        // WHMCS-PERM-01 (v1.9.6): ... and only for a user with the "Perform Single Sign-On"
        // permission; anyone else gets the read-only overview.
        $mayManage = $active && self::userMayManage($params);
        $reason    = null;
        if (!$active) {
            $reason = 'DNS management is not available while this service is '
                . ($serviceStatus !== '' ? $serviceStatus : 'not active') . '.';
        } elseif (!$mayManage) {
            $reason = 'DNS management is not available to your user account for this service.';
        }

        $notice = null;
        if (!$mayManage && ($action === 'sso' || in_array($action, self::EMBEDDED_ACTIONS, true))) {
            $notice = $active ? self::MSG_NO_PERMISSION : $reason;
            $action = '';
        }

        // SSO-CLIENT-01: handle the in-template "Open full DNS Portal" button.
        // Mints a 60-second SSO token and redirects via a JS template.
        // ServiceSingleSignOn() handles the same flow from WHMCS's own link button.
        if ($action === 'sso') {
            $id = $svc->subClientId();
            if ($id) {
                $resp = $svc->api->mintSubClientSsoToken($id);
                if ($resp['ok'] && !empty($resp['data']['login_url'])) {
                    return [
                        'templatefile' => 'templates/client/sso-redirect',
                        'vars'         => ['redirect_url' => $resp['data']['login_url']],
                    ];
                }
            }
            return [
                'templatefile' => 'templates/client/overview',
                'vars'         => [
                    'paneldns_error'       => 'Could not generate portal login link.',
                    'paneldns_nameservers' => [],
                ],
            ];
        }

        if (in_array($action, self::EMBEDDED_ACTIONS, true)) {
            $subClientId = $svc->subClientId();
            if (!$subClientId) {
                return [
                    'templatefile' => 'templates/client/overview',
                    'vars' => ['paneldns_error' => 'Service not provisioned yet.', 'paneldns_nameservers' => []],
                ];
            }
            $mgr = new PanelDnsEmbeddedDnsManager($svc->api, $subClientId, $params);
            return $mgr->handle($action);
        }

        // Default — render the overview with usage cards.
        $vars = [
            'paneldns_sub_client_id' => $svc->subClientId(), // NO-EMAIL-LINK-03: dedicatedip only
            'paneldns_can_manage'    => $mayManage,          // WHMCS-PERM-01 / SVC-STATUS-01: show the DNS buttons
            'paneldns_manage_reason' => $reason,
            'paneldns_notice'        => $notice,
            'paneldns_status'        => 'unknown',
            'paneldns_usage'         => null,
            'paneldns_limits'        => null,
            'paneldns_error'         => null,
            'paneldns_nameservers'   => [], // NS-01: populated below once API is available
            'paneldns_zones_health'  => null,
        ];

        $id = $svc->subClientId();
        if (!$id) {
            return ['templatefile' => 'templates/client/overview', 'vars' => $vars];
        }

        // RATE-CALLS-01: summary and zone health are cached together for 60 s and the
        // nameservers for 5 min, so a page view is usually 0–1 API calls (was 3–4).
        $cacheKey = 'paneldns-client-sub-summary:' . $id;
        $data = self::cacheGet($cacheKey);

        if (!is_array($data) || !array_key_exists('zones_health', $data)) {
            $resp = $svc->api->subClientSummary($id);
            if (!$resp['ok']) {
                if (function_exists('logModuleCall')) {
                    logModuleCall(self::MODULE, 'clientArea:summary', ['sub_client_id' => $id], (string) $resp['error'], '');
                }
                // Clients see a generic line; the detail is in the module log.
                $vars['paneldns_error'] = (int) $resp['status'] === 429
                    ? 'The DNS service is busy right now'
                    : 'Live account data is unavailable right now';
                $vars['paneldns_nameservers'] = $svc->resolveNameservers();
                return ['templatefile' => 'templates/client/overview', 'vars' => $vars];
            }
            $data = (array) $resp['data'];

            // Feature 6 — zone health widget: the client's zones that are NOT active, so
            // problems show first. PAGING-01: follows the pages (50 each), up to 200 zones.
            $troubled = [];
            $zonesResp = $svc->api->getAllPages('/api/v1/zones', ['sub_client_id' => $id], 4);
            foreach ($zonesResp['data'] as $z) {
                $zStatus = (string) ($z['status'] ?? 'active');
                if ($zStatus !== 'active') {
                    $troubled[] = ['name' => (string) ($z['name'] ?? ''), 'status' => $zStatus];
                }
            }
            $data['zones_health'] = $troubled ?: null;

            self::cachePut($cacheKey, $data, self::TTL_SUMMARY);
        }

        $vars['paneldns_status']       = $data['sub_client']['status'] ?? 'unknown';
        $vars['paneldns_usage']        = $data['usage'] ?? null;
        $vars['paneldns_limits']       = $data['limits'] ?? null;
        $vars['paneldns_zones_health'] = $data['zones_health'] ?? null;
        // NS-01: always show nameservers so clients know where to point their domains.
        $vars['paneldns_nameservers']  = $svc->resolveNameservers();

        // CONSENT-R-04 (v1.9.3): the re-consent banner was removed. It read
        // sub_client.requires_consent and sub_client.portal_sso_url, neither of which
        // PanelDNS's sub-client resource returns, so it could never show.

        return ['templatefile' => 'templates/client/overview', 'vars' => $vars];
    }

    /** Merge fields shared by the welcome and resend paths. */
    private function welcomeContext(): array
    {
        // For the reseller-tier portal URL we use /api/v1/summary's links.portal.
        $context = [];
        $org = $this->orgSummary();
        if ($org !== null) {
            $context['portal_url'] = (string) ($org['links']['portal'] ?? '');
            $context['org_slug']   = (string) ($org['org']['slug'] ?? '');
        }

        // T2.3 / NS-01: per-product overrides (configoption4-7), else the org's NS.
        $ns = $this->resolveNameservers();
        if (!empty($ns)) {
            $context['nameservers'] = implode("\n", $ns);
        }

        // SOA email override (configoption8) — passed through as merge field
        // for templates that surface it.
        $soaEmail = trim((string) ($this->params['configoption8'] ?? ''));
        if ($soaEmail !== '') {
            $context['soa_email'] = $soaEmail;
        }
        return $context;
    }

    private function sendWelcomeEmail(int $subClientId): void
    {
        $serviceId = (int) ($this->params['serviceid'] ?? 0);
        if ($serviceId <= 0) return;

        $sso = $this->api->mintSubClientSsoToken($subClientId);
        if (!$sso['ok'] || empty($sso['data']['login_url'])) return;

        PanelDnsResellerWelcomeMail::sendReseller($serviceId, $sso['data'], $this->welcomeContext());
    }
}
