<?php

/**
 * LicenceCheck — verifies the WHMCS install is bundled with an active
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

class PanelDnsLicenceCheck
{
    const REQUIRED_MODULE_PLATFORM = 'whmcs-platform';
    const REQUIRED_MODULE_RESELLER = 'whmcs-reseller';

    /** Past-due grace period — past this, lock provisioning. */
    const GRACE_SECONDS = 7 * 86400;
    /** Refresh interval — re-fetch from the server this often. */
    const CACHE_TTL = 86400;
    /** If the API has been unreachable longer than this, lock. */
    const STALE_HARD_LOCK = 14 * 86400;

    /**
     * Check whether the named module is unlocked for the configured server.
     *
     * @return array{unlocked:bool, reason:string, sub_status:string, expires_at:?string}
     */
    public static function check(PanelDnsApi $api, string $requiredModule): array
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
            self::writeCache($cacheKey, [
                'fetched_at' => $now,
                'sub_status' => $payload['sub_status'] ?? 'unknown',
                'modules'    => $payload['modules_unlocked'] ?? [],
                'expires_at' => $payload['expires_at'] ?? null,
                'current_plan' => $payload['current_plan'] ?? null,
            ]);
            return self::interpret([
                'fetched_at' => $now,
                'sub_status' => $payload['sub_status'] ?? 'unknown',
                'modules'    => $payload['modules_unlocked'] ?? [],
                'expires_at' => $payload['expires_at'] ?? null,
            ], $requiredModule, $now);
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
            'reason'     => 'Cannot reach PanelDNS to verify licence: ' . ($resp['error'] ?: 'unknown'),
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
    public static function gateOrError(PanelDnsApi $api, string $requiredModule): ?string
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

        // past_due — give 7-day grace from the LAST KNOWN GOOD fetch.
        if ($sub === 'past_due' && $hasModule) {
            $secondsPastDue = $now - $fetched;
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

    private static function cacheKey(PanelDnsApi $api): string
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
        try {
            if (class_exists('\\WHMCS\\Cache\\Store')) {
                $store = new \WHMCS\Cache\Store();
                $store->put($key, $value, self::STALE_HARD_LOCK); // expire the cache entry past hard lock
            }
        } catch (\Throwable $e) { /* swallow */ }
    }
}
