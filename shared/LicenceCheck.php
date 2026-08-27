<?php

/**
 * PanelDnsResellerLicenceCheck — verifies the WHMCS install is bundled with an active
 * PanelDNS subscription, with a 7-day grace period for past_due.
 *
 * Behavior:
 *   - Calls GET /api/v1/licence-status on the configured PanelDNS server
 *   - Cached 24h via WHMCS Cache (\WHMCS\Cache\Store) keyed on the server host
 *   - sub_status='active' or 'trialing'   → unlocked
 *   - sub_status='past_due' and cache_age < 7 days → still unlocked (grace)
 *   - past 7-day grace OR cache_age > 7 days → locked → CreateAccount blocked
 *   - Existing services keep working (Suspend / Unsuspend / Terminate not gated)
 *   - 'free' sub_status → locked from day one (no provisioning past trial)
 *
 * Cache failures are non-fatal — fall back to the last known good response
 * if any. This avoids "the module breaks because of a transient network
 * hiccup". The cache itself is a key/value JSON blob in the WHMCS Cache.
 *
 * NB: this lives in shared/ and is symlinked/copied into both modules.
 */

if (!defined('WHMCS')) {
    die('Access denied.');
}

class PanelDnsResellerLicenceCheck
{
    const REQUIRED_MODULE_PLATFORM = 'whmcs-platform';
    const REQUIRED_MODULE_RESELLER = 'whmcs-reseller';

    /** Past-due grace period — past this, lock provisioning. */
    const GRACE_SECONDS = 7 * 86400;
    /** Refresh interval — re-fetch from the server this often. */
    const CACHE_TTL = 86400;
    /** FIX-H1: reduced from 14 days to 2 days. Faster lock-out when API
     *  is unreachable, reducing the window where a lapsed licence keeps working. */
    const STALE_HARD_LOCK = 2 * 86400;

    /**
     * Check whether the named module is unlocked for the configured server.
     *
     * @return array{unlocked:bool, reason:string, sub_status:string, expires_at:?string}
     */
    public static function check(PanelDnsResellerApi $api, string $requiredModule): array
    {
        $cacheKey = self::cacheKey($api);
        $cached   = self::readCache($cacheKey);
        $now      = time();

        // Cache fresh? Use it.
        if ($cached && ($now - $cached['fetched_at']) < self::CACHE_TTL) {
            return self::interpret($cached, $requiredModule, $now);
        }

        // Fetch fresh.
        $resp = $api->licenceStatus();
        if ($resp['ok']) {
            $payload = $resp['data'] ?? [];
            $subStatus = $payload['sub_status'] ?? 'unknown';
            // FIX-H1: preserve first_past_due_at from the existing cache so grace
            // is measured from when past_due was first observed, not from cache age.
            // GRACE-CLOCK-02: the `&& $cached` guard discarded the timestamp on the
            // FIRST past-due observation (when no cache exists yet), writing null. The
            // next refresh a day later then set it to that later now, so the clock
            // started one cache cycle late and grace ran 8 days instead of 7.
            $firstPastDueAt = $subStatus === 'past_due'
                ? ($cached['first_past_due_at'] ?? $now)
                : null;
            $newCache = [
                'fetched_at'       => $now,
                'sub_status'       => $subStatus,
                'modules'          => $payload['modules_unlocked'] ?? [],
                'expires_at'       => $payload['expires_at'] ?? null,
                'current_plan'     => $payload['current_plan'] ?? null,
                'first_past_due_at' => $firstPastDueAt,
            ];
            self::writeCache($cacheKey, $newCache);
            return self::interpret($newCache, $requiredModule, $now);
        }

        // Couldn't reach the server. Fall back to last-known cache if recent enough,
        // otherwise lock.
        if ($cached && ($now - $cached['fetched_at']) < self::STALE_HARD_LOCK) {
            $stale = self::interpret($cached, $requiredModule, $now);
            $stale['reason'] = 'Stale (PanelDNS unreachable; using cached licence). ' . $stale['reason'];
            return $stale;
        }

        return [
            'unlocked'   => false,
            // SEC-M14: generic error — raw cURL errors (hostnames, SSL detail) must not
            // reach the WHMCS admin banner where they could aid reconnaissance.
            'reason'     => self::failureReason($resp),
            'sub_status' => 'unknown',
            'expires_at' => null,
        ];
    }

    /**
     * Convenience wrapper for the lifecycle hooks. Returns null if unlocked
     * (i.e. proceed) or a multi-line error message if locked.
     *
     * The error message is what WHMCS surfaces in the Module Commands area
     * after a failed CreateAccount, so it needs to be:
     *   - Human-readable (the operator reads it; no API-shape jargon)
     *   - Actionable (tells them WHERE to fix it)
     *   - Specific (which subscription state, when expires)
     *
     * T1.3: structured message with a reactivation URL pulled from the
     * addon module's configured value (operator sets it in
     * Setup → Addon Modules → PanelDNS → Reactivation URL).
     */
    public static function gateOrError(PanelDnsResellerApi $api, string $requiredModule): ?string
    {
        $result = self::check($api, $requiredModule);
        if ($result['unlocked']) return null;

        return self::formatErrorBanner($result);
    }

    /**
     * T1.3: render a friendly error banner for the WHMCS module log + admin UI.
     *
     * @internal exposed for unit testing.
     */
    public static function formatErrorBanner(array $result): string
    {
        $sub = $result['sub_status'] ?? 'unknown';
        $expiresAt = $result['expires_at'] ?? null;

        $headline = match (true) {
            $sub === 'cancelled'  => '⚠ PanelDNS subscription cancelled',
            $sub === 'past_due'   => '⚠ PanelDNS subscription past due (grace expired)',
            $sub === 'free'       => '⚠ No active PanelDNS subscription',
            $sub === 'unknown'    => '⚠ Could not verify PanelDNS subscription',
            default               => '⚠ PanelDNS licence check failed',
        };

        $explainer = match (true) {
            $sub === 'cancelled' => 'New provisioning is disabled. Existing customers keep working.',
            $sub === 'past_due'  => 'The 7-day grace period after a past-due subscription has expired. Provisioning is paused until the subscription is reinstated.',
            $sub === 'free'      => 'The paneldns-reseller WHMCS module requires an active PanelDNS subscription. Free-tier accounts can manage DNS via the portal but cannot provision through WHMCS.',
            $sub === 'unknown'   => 'The PanelDNS server could not be reached to verify the licence. Check the server hostname and access hash in Setup → Servers.',
            default              => $result['reason'] ?? '',
        };

        $reactivationUrl = self::reactivationUrl();
        // FIX-L3: only accept HTTPS reactivation URLs — reject any other scheme.
        if (!str_starts_with((string) $reactivationUrl, 'https://')) {
            $reactivationUrl = '';
        }
        // SEC-L04: escape the URL before embedding in the banner — value comes from DB.
        $action = $reactivationUrl
            ? 'Reactivate at: ' . htmlspecialchars($reactivationUrl, ENT_QUOTES, 'UTF-8')
            : 'Reactivate at: your PanelDNS billing page (set the Reactivation URL in Setup → Addon Modules → PanelDNS).';

        $expiry = $expiresAt
            ? "Subscription expired: {$expiresAt}"
            : '';

        $lines = array_filter([
            $headline,
            '',
            $explainer,
            $expiry,
            '',
            $action,
        ], fn ($l) => $l !== '');

        return implode("\n", $lines);
    }

    /**
     * Read the Reactivation URL from the addon module's settings.
     * Returns empty string if the addon isn't installed.
     */
    private static function reactivationUrl(): string
    {
        try {
            $val = \WHMCS\Database\Capsule::table('tbladdonmodules')
                ->where('module', 'paneldns')
                ->where('setting', 'reactivation_url')
                ->value('value');
            return is_string($val) ? $val : '';
        } catch (\Throwable $e) {
            return '';
        }
    }

    // ── Internals ────────────────────────────────────────────────────────────

    /**
     * Pure decision function — exposed for unit testing.
     * Takes a cached licence payload and decides whether the module
     * should be unlocked.
     *
     * @internal Public only so tests can call it without reflection.
     */
    public static function interpret(array $cached, string $requiredModule, int $now): array
    {
        $sub    = $cached['sub_status'] ?? 'unknown';
        $mods   = $cached['modules'] ?? [];
        $expAt  = $cached['expires_at'] ?? null;
        $fetched = $cached['fetched_at'] ?? 0;

        // Module unlocked?
        $hasModule = in_array($requiredModule, $mods, true);

        // Active / trialing — always good.
        if (in_array($sub, ['active', 'trialing'], true) && $hasModule) {
            return [
                'unlocked'   => true,
                'reason'     => "Subscription {$sub}",
                'sub_status' => $sub,
                'expires_at' => $expAt,
            ];
        }

        // past_due — give 7-day grace from when past_due was FIRST observed.
        // FIX-H1: use first_past_due_at (set when past_due first appears) rather than
        // fetched_at, so the grace clock is accurate regardless of cache refresh timing.
        if ($sub === 'past_due' && $hasModule) {
            $firstPastDueAt = $cached['first_past_due_at'] ?? $fetched;
            $secondsPastDue = $now - $firstPastDueAt;
            if ($secondsPastDue < self::GRACE_SECONDS) {
                $daysLeft = (int) ceil((self::GRACE_SECONDS - $secondsPastDue) / 86400);
                return [
                    'unlocked'   => true,
                    'reason'     => "Subscription past due (grace: {$daysLeft} day(s) left)",
                    'sub_status' => $sub,
                    'expires_at' => $expAt,
                ];
            }
            return [
                'unlocked'   => false,
                'reason'     => "Subscription past due — grace period expired",
                'sub_status' => $sub,
                'expires_at' => $expAt,
            ];
        }

        // Cancelled / free / unknown — locked.
        return [
            'unlocked'   => false,
            'reason'     => "Subscription status: {$sub}" . ($hasModule ? '' : ' (module not unlocked)'),
            'sub_status' => $sub,
            'expires_at' => $expAt,
        ];
    }

    private static function cacheKey(PanelDnsResellerApi $api): string
    {
        // Stable per-server identifier — same key across requests for the
        // same server+credential tuple. Never embeds the raw API key.
        return 'paneldns-whmcs-licence:' . $api->identityHash();
    }

    private static function readCache(string $key): ?array
    {
        try {
            if (class_exists('\\WHMCS\\Cache\\Store')) {
                $store = new \WHMCS\Cache\Store();
                $val = $store->get($key);
                return is_array($val) ? $val : null;
            }
        } catch (\Throwable $e) { /* swallow */ }
        return null;
    }

    private static function writeCache(string $key, array $value): void
    {
        // FIX-H1: only write cache if the payload has a valid shape — prevents
        // caching a malformed API response that could cause interpret() to malfunction.
        if (
            !is_string($value['sub_status'] ?? null) || $value['sub_status'] === '' ||
            !is_array($value['modules'] ?? null)
        ) {
            return;
        }
        try {
            if (class_exists('\\WHMCS\\Cache\\Store')) {
                $store = new \WHMCS\Cache\Store();
                $store->put($key, $value, self::STALE_HARD_LOCK); // expire the cache entry past hard lock
            }
        } catch (\Throwable $e) { /* swallow */ }
    }

    /**
     * Describe WHY the licence check failed, distinguishing a server we could not
     * reach from one that reached us and refused.
     *
     * LICENCE-DIAG-01: every failure previously reported "Cannot reach PanelDNS".
     * A 401/403 is not unreachability - the server answered. Since PanelDNS v3.91.6
     * a suspended org receives 403 with an actionable message ("settle any
     * outstanding balance in the billing area"), and reporting that as a
     * connectivity fault sends the reseller to debug their network instead of their
     * invoice. PanelDNS v3.91.7 exempts licence-status from the suspension gate so
     * this path should no longer see a suspension 403, but the same reasoning holds
     * for an expired or revoked token.
     */
    private static function failureReason(array $resp): string
    {
        $status = (int) ($resp['status'] ?? 0);
        $error  = trim((string) ($resp['error'] ?? ''));

        if ($status >= 400 && $status < 500) {
            return $error !== ''
                ? "PanelDNS refused the licence check (HTTP {$status}): {$error}"
                : "PanelDNS refused the licence check (HTTP {$status}). Check the API token.";
        }

        return $error !== ''
            ? "Cannot reach PanelDNS to verify licence: {$error}"
            : 'Cannot reach PanelDNS to verify licence. Please try again later.';
    }
}
