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
 * DOMAIN-01: auto-create a DNS zone when a domain is registered, or when its transfer
 * has COMPLETED, for a client with an active service of this module. Gated per-product
 * by "Auto-Create Zone on Domain Order", and only for a domain on that client's own
 * WHMCS account (ZONE-OWN-04).
 *
 * ZONE-OWN-04 (v1.9.5): AfterRegistrarTransfer is no longer hooked. WHMCS runs it when
 * the transfer command has been sent to the registrar, whether or not it succeeded, so
 * ordering a transfer of someone else's domain created that domain's zone under the
 * orderer's sub-client. DomainTransferCompleted runs when WHMCS's domain sync cron
 * marks the transfer completed. https://developers.whmcs.com/hooks-reference/domain/
 */
add_hook('AfterRegistrarRegistration', 1, function (array $vars) {
    PanelDnsResellerHooks::onDomainRegistered($vars);
});
add_hook('DomainTransferCompleted', 1, function (array $vars) {
    PanelDnsResellerHooks::onDomainTransferCompleted($vars);
});

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
 * ADDON-01 / ADDON-ZONES-01 (v1.9.6): re-apply the sub-client's zone limit when a
 * PanelDNS zone-pack addon ("PanelDNS +N Zones") changes state. The limit is recomputed
 * from the product limit plus the service's active zone packs, through the module's own
 * ChangePackage, so the order of events cannot make it drift. Every addon hook passes
 * 'id' (tblhostingaddons.id), 'serviceid' and 'addonid' at the top level.
 * https://developers.whmcs.com/hooks-reference/addon/
 *
 * Up to 1.9.5 only Activated (+), Suspended (-) and Terminated (-) were hooked, and any
 * addon counted (5 zones by default).
 */
foreach ([
    'AddonActivated'   => true,
    'AddonUnsuspended' => true,
    'AddonSuspended'   => false,
    'AddonTerminated'  => false,
    'AddonCancelled'   => false,
] as $addonHook => $addonActive) {
    add_hook($addonHook, 1, function (array $vars) use ($addonActive) {
        PanelDnsResellerHooks::onAddonChange(
            (int) ($vars['serviceid'] ?? 0),
            (int) ($vars['addonid'] ?? 0),
            (int) ($vars['id'] ?? 0),
            $addonActive
        );
    });
}
unset($addonHook, $addonActive);

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
 * PAY-02 (v1.9.5): no invoice-payment hook. Unsuspension on payment is WHMCS's job.
 *
 * Up to 1.9.4 an `InvoicePaymentSuccess` hook (PAY-01) set every Suspended service of
 * this module of the client to active in PanelDNS and Active in WHMCS on ANY payment:
 * any invoice (an Add Funds one too), any suspension reason (abuse, a manual hold),
 * other invoices still overdue. WHMCS has no hook of that name, so it should never have
 * run; renamed to InvoicePaid it would have.
 * https://developers.whmcs.com/hooks-reference/invoices-and-quotes/
 *
 * WHMCS already does this correctly: when an overdue invoice is paid and Automation
 * Settings → Enable Unsuspension is on, it unsuspends a service it suspended for
 * non-payment ("Overdue on Payment") by calling paneldnsreseller_UnsuspendAccount, and
 * leaves a custom-reason suspension alone. https://docs.whmcs.com/billing-and-invoicing/billing-logic
 * Do not add an invoice hook (InvoicePaid, InvoicePaidPreEmail, AddInvoicePayment) that
 * unsuspends or writes tblhosting.domainstatus.
 */
