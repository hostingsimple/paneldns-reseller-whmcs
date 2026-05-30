<?php

/**
 * WelcomeMail — dispatch a "your PanelDNS account is ready" email with a
 * 60-second SSO link.
 *
 * Uses WHMCS's sendMessage() helper with custom merge fields. The customer
 * gets:
 *   - {$paneldns_login_url}    — one-time signed SSO URL
 *   - {$paneldns_login_expires} — "60 seconds" / human readable
 *   - {$paneldns_portal_url}    — long-lived portal URL (no SSO)
 *
 * Two email template names are required (operator creates them in WHMCS
 * Admin → Setup → Email Templates → Product Welcome Emails):
 *
 *   "PanelDNS Platform Welcome"   — for paneldns-platform (operator selling reseller accounts)
 *   "PanelDNS Reseller Welcome"   — for paneldns-reseller (reseller selling DNS hosting)
 *
 * If the template is missing, sendMessage() returns false and we log+swallow
 * — no fatal error blocking provisioning.
 */

if (!defined('WHMCS')) {
    die('Access denied.');
}

class PanelDnsWelcomeMail
{
    const TEMPLATE_PLATFORM = 'PanelDNS Platform Welcome';
    const TEMPLATE_RESELLER = 'PanelDNS Reseller Welcome';

    /**
     * Send the platform-mode welcome email to a freshly provisioned reseller.
     *
     * @param int   $serviceId WHMCS hosting service ID
     * @param array $ssoData   Response from POST /platform/v1/orgs/{id}/sso-token,
     *                         shape: {login_url, expires_in, user:{...}}
     * @param array $context   Extra merge fields (org_slug, portal_url, etc.)
     */
    public static function sendPlatform(int $serviceId, array $ssoData, array $context = []): bool
    {
        return self::send(self::TEMPLATE_PLATFORM, $serviceId, $ssoData, $context);
    }

    /**
     * Send the reseller-mode welcome email to a freshly provisioned sub-client.
     */
    public static function sendReseller(int $serviceId, array $ssoData, array $context = []): bool
    {
        return self::send(self::TEMPLATE_RESELLER, $serviceId, $ssoData, $context);
    }

    /**
     * @return bool true on apparent success, false on swallow
     */
    private static function send(string $templateName, int $serviceId, array $ssoData, array $context): bool
    {
        $merge = array_merge([
            'paneldns_login_url'      => $ssoData['login_url'] ?? '',
            'paneldns_login_expires'  => self::humanExpiry($ssoData['expires_in'] ?? 60),
            'paneldns_portal_url'     => $context['portal_url'] ?? '',
            'paneldns_org_slug'       => $context['org_slug'] ?? '',
            'paneldns_nameservers'    => $context['nameservers'] ?? '',
        ], $context);

        try {
            // WHMCS Admin API: SendEmail with custom merge fields.
            if (function_exists('localAPI')) {
                $result = localAPI('SendEmail', [
                    'messagename' => $templateName,
                    'id'          => $serviceId,
                    'customtype'  => 'product',
                    'customvars'  => base64_encode(serialize($merge)),
                ]);

                if (($result['result'] ?? '') !== 'success') {
                    self::log("template not found or send failed: {$templateName}", $result);
                    return false;
                }
                return true;
            }
        } catch (\Throwable $e) {
            self::log("welcome mail exception: " . $e->getMessage(), []);
        }

        return false;
    }

    /** @internal exposed for unit testing */
    public static function humanExpiry(int $seconds): string
    {
        if ($seconds <= 60)  return "{$seconds} seconds";
        if ($seconds < 3600) return floor($seconds / 60) . ' minutes';
        return floor($seconds / 3600) . ' hours';
    }

    private static function log(string $msg, array $ctx): void
    {
        if (function_exists('logModuleCall')) {
            logModuleCall('paneldns', 'WelcomeMail', $msg, $ctx);
        }
    }
}
