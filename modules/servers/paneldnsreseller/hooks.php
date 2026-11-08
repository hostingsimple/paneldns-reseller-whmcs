<?php

/**
 * paneldnsreseller module hooks.
 *
 * DailyCronJob fires drift reconciliation for this module's services only
 * (paneldnsreseller); the operator module (paneldnsplatform) reconciles its own.
 *
 * Every file this loads lives under this module's own lib/ — the release ZIP must be
 * self-contained (BUILD-ZIP-01: up to v1.9.2 this required ../../../shared/, which is
 * not in the ZIP, and the module fataled on load).
 *
 * WHMCS loads a provisioning module's hooks.php only once the module is "activated",
 * i.e. after a product using it has been saved. See INSTALL.md → Upgrading.
 */

if (!defined('WHMCS')) {
    die('Access denied.');
}

require_once __DIR__ . '/lib/PanelDnsApi.php';
require_once __DIR__ . '/lib/DriftSync.php';
require_once __DIR__ . '/lib/PanelDnsResellerMigration.php';
require_once __DIR__ . '/lib/PanelDnsResellerHooks.php';

/**
 * MODULE-NAME-01: migrate products/servers still stored under the pre-1.9.3 module names
 * (paneldns-reseller, paneldns_reseller). pending() is two existence checks on small
 * tables, and runIfPending() runs at most once per request.
 */
add_hook('AdminAreaPage', 1, function (array $vars) {
    PanelDnsResellerMigration::runIfPending();
    return [];
});

add_hook('DailyCronJob', 1, function () {
    // Migrate first, so the drift sync and grace cron below see the new module name.
    PanelDnsResellerMigration::runIfPending();
    try {
        PanelDnsResellerDriftSync::run();
    } catch (\Throwable $e) {
        if (function_exists('logActivity')) {
            // SEC-L06: log class only — exception messages may contain PII or SQL fragments.
            logActivity('PanelDNS drift sync (reseller) hook crashed: ' . get_class($e));
        }
    }
});

/**
 * DOMAIN-01: auto-create a DNS zone when a domain is registered or transferred
 * via a registrar module. Both hooks pass the registrar module params nested
 * under $vars['params'] — the domain is sld+tld (tld includes the dot), and
 * many registrars also expose a convenience 'domainname'. Gated per-product by
 * "Auto-Create Zone on Domain Order".
 */
$paneldnsDomainCreate = function (array $vars) {
    $p      = $vars['params'] ?? [];
    $domain = $p['domainname'] ?? (($p['sld'] ?? '') . ($p['tld'] ?? ''));
    PanelDnsResellerHooks::onDomainEvent((int) ($p['userid'] ?? 0), (string) $domain, 'create');
};
add_hook('AfterRegistrarRegistration', 1, $paneldnsDomainCreate);
add_hook('AfterRegistrarTransfer', 1, $paneldnsDomainCreate);

/**
 * DOMAIN-02: optionally delete the DNS zone when a domain is deleted. The
 * DomainDelete hook passes 'userid' and 'domainid' at the top level and no
 * domain name, so the name is resolved from tbldomains. Gated per-product by
 * "Auto-Delete Zone on Domain Expiry" (default: off).
 */
add_hook('DomainDelete', 1, function (array $vars) {
    $userId   = (int) ($vars['userid'] ?? 0);
    $domainId = (int) ($vars['domainid'] ?? 0);

    $domain = '';
    if ($domainId > 0) {
        try {
            $domain = (string) \WHMCS\Database\Capsule::table('tbldomains')
                ->where('id', $domainId)
                ->value('domain');
        } catch (\Throwable $e) {
            // fall through — onDomainEvent no-ops on empty domain
        }
    }

    PanelDnsResellerHooks::onDomainEvent($userId, $domain, 'delete');
});

/**
 * ADDON-01: adjust the sub-client zone limit when a product addon is activated
 * (+) or suspended/terminated (-). All three hooks pass 'serviceid' and
 * 'addonid' at the top level; the addon name is resolved from tbladdons.
 */
add_hook('AddonActivated', 1, function (array $vars) {
    PanelDnsResellerHooks::onAddonChange((int) ($vars['serviceid'] ?? 0), (int) ($vars['addonid'] ?? 0), +1);
});
add_hook('AddonSuspended', 1, function (array $vars) {
    PanelDnsResellerHooks::onAddonChange((int) ($vars['serviceid'] ?? 0), (int) ($vars['addonid'] ?? 0), -1);
});
add_hook('AddonTerminated', 1, function (array $vars) {
    PanelDnsResellerHooks::onAddonChange((int) ($vars['serviceid'] ?? 0), (int) ($vars['addonid'] ?? 0), -1);
});

/**
 * CLIENT-SYNC-01: push WHMCS client name changes to PanelDNS.
 * Fires whenever any client field is updated (admin area or client portal).
 * Fetches current values from tblclients rather than trusting hook vars alone.
 *
 * CLIENT-SYNC-02 (v1.9.3): only the name is sent. PATCH /api/v1/sub-clients/{id}
 * accepts name, status, zone_limit and max_records; it ignores email (kept fixed as
 * the sub-client's SSO identity) and locale.
 */
add_hook('ClientEdit', 1, function (array $vars) {
    $userId = (int) ($vars['userid'] ?? 0);
    if ($userId <= 0) return;
    try {
        $client = \WHMCS\Database\Capsule::table('tblclients')
            ->where('id', $userId)
            ->select(['firstname', 'lastname'])
            ->first();
        if (!$client) return;
        PanelDnsResellerHooks::onClientEdit(
            $userId,
            (string) $client->firstname,
            (string) $client->lastname
        );
    } catch (\Throwable $e) {
        if (function_exists('logActivity')) {
            // SEC-L06: class only, no raw exception message
            logActivity('PanelDNS ClientEdit hook crashed: ' . get_class($e));
        }
    }
});

/**
 * GRACE-02: nightly job to hard-delete sub-clients whose grace period has elapsed.
 * Runs alongside the drift sync cron — both are idempotent so ordering doesn't matter.
 */
add_hook('DailyCronJob', 1, function () {
    try {
        PanelDnsResellerHooks::processExpiredGracePeriods();
    } catch (\Throwable $e) {
        if (function_exists('logActivity')) {
            logActivity('PanelDNS grace period cron crashed: ' . get_class($e));
        }
    }
});

/**
 * PAY-01: auto-unsuspend a sub-client when a past-due invoice is paid.
 *
 * Without this hook, a client who pays their overdue invoice stays suspended
 * until the next DailyCronJob drift sync (up to 24 hours). This hook fires
 * immediately on payment and unsuspends right away.
 *
 * WHMCS InvoicePaymentSuccess passes 'invoiceid' and 'userid' at the top level.
 * The hook checks every Suspended paneldnsreseller service for that client,
 * so it handles the edge case of a client with multiple DNS products.
 */
add_hook('InvoicePaymentSuccess', 1, function (array $vars) {
    try {
        PanelDnsResellerHooks::onInvoicePaid((int) ($vars['userid'] ?? 0));
    } catch (\Throwable $e) {
        if (function_exists('logActivity')) {
            logActivity('PanelDNS InvoicePaymentSuccess hook crashed: ' . get_class($e));
        }
    }
});
