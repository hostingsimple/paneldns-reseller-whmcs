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

add_hook('DailyCronJob', 1, function () {
    try {
        PanelDnsDriftSync::run();
    } catch (\Throwable $e) {
        if (function_exists('logActivity')) {
            logActivity('PanelDNS drift sync (reseller) hook crashed: ' . $e->getMessage());
        }
    }
});
