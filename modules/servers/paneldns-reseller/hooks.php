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
require_once __DIR__ . '/lib/DriftSync.php';
require_once __DIR__ . '/lib/PanelDnsResellerHooks.php';

add_hook('DailyCronJob', 1, function () {
    try {
        PanelDnsDriftSync::run();
    } catch (\Throwable $e) {
        if (function_exists('logActivity')) {
            logActivity('PanelDNS drift sync (reseller) hook crashed: ' . $e->getMessage());
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
