<?php

/**
 * paneldns-reseller module hooks.
 *
 * DailyCronJob fires drift reconciliation. Both modules' hooks are
 * idempotent — DriftSync filters by servertype internally, so it only
 * touches its own services regardless of which hook fires first.
 *
 * If both modules are installed, both hooks fire (in order priority 1)
 * but the second invocation is a no-op because the first already
 * processed up to MAX_PER_RUN services that round.
 *
 * Actually, the second invocation will pick up DIFFERENT services since
 * MAX_PER_RUN limits each call separately — so this is fine.
 */

if (!defined('WHMCS')) {
    die('Access denied.');
}

require_once __DIR__ . '/lib/PanelDnsApi.php';
// FIX-C1: DriftSync lives in shared/ — the lib/ path does not exist.
require_once __DIR__ . '/../../../shared/DriftSync.php';
require_once __DIR__ . '/lib/PanelDnsResellerHooks.php';

add_hook('DailyCronJob', 1, function () {
    try {
        PanelDnsDriftSync::run();
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
 * CLIENT-SYNC-01: push WHMCS client profile changes to PanelDNS.
 * Fires whenever any client field is updated (admin area or client portal).
 * Fetches current values from tblclients rather than trusting hook vars alone.
 */
add_hook('ClientEdit', 1, function (array $vars) {
    $userId = (int) ($vars['userid'] ?? 0);
    if ($userId <= 0) return;
    try {
        $client = \WHMCS\Database\Capsule::table('tblclients')
            ->where('id', $userId)
            ->select(['firstname', 'lastname', 'email'])
            ->first();
        if (!$client) return;
        PanelDnsResellerHooks::onClientEdit(
            $userId,
            (string) $client->firstname,
            (string) $client->lastname,
            (string) $client->email
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
 * The hook checks every Suspended paneldns-reseller service for that client,
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
