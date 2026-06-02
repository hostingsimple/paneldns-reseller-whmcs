{*
  PanelDNS Reseller — client area overview.
  Phase 2: live usage cards from /api/v1/sub-clients/{id}/summary.
*}

<div class="paneldns-client" style="max-width:880px; margin:24px auto;">

    {if $paneldns_status === 'suspended'}
        <div style="background:#fef3c7; border:1px solid #fbbf24; padding:12px 16px; border-radius:8px; color:#92400e; font-size:13px; margin-bottom:16px;">
            <strong>Suspended.</strong> Your DNS account is currently suspended.
        </div>
    {/if}

    {if $paneldns_error}
        <div style="background:#fee2e2; border:1px solid #fca5a5; padding:12px 16px; border-radius:8px; color:#991b1b; font-size:13px; margin-bottom:16px;">
            Could not load live data: {$paneldns_error|escape}. Try refreshing the page.
        </div>
    {/if}

    <div class="card" style="padding:24px; background:#fff; border:1px solid #e5e7eb; border-radius:10px; margin-bottom:16px;">
        <h2 style="margin:0 0 8px; font-size:20px;">Your DNS Account</h2>
        <p style="margin:0 0 18px; color:#6b7280; font-size:14px;">
            Manage your zones and DNS records via the customer portal.
        </p>

        {if $paneldns_sub_client_id}
            <div style="display:flex;gap:10px;flex-wrap:wrap;">
                {* T1.4: primary CTA is now the in-page DNS manager *}
                <a href="clientarea.php?action=productdetails&id={$serviceid}&modop=custom&a=zones"
                   style="background:#0891b2; color:#fff; padding:10px 18px; border-radius:8px; text-decoration:none; font-weight:600; font-size:14px; display:inline-block;">
                    Manage DNS Zones →
                </a>
                <a href="clientarea.php?action=productdetails&id={$serviceid}&modop=custom&a=sso"
                   style="background:#fff; color:#0891b2; padding:10px 18px; border:1px solid #0891b2; border-radius:8px; text-decoration:none; font-weight:600; font-size:14px; display:inline-block;">
                    Open full DNS Portal
                </a>
            </div>
            <p style="margin:10px 0 0;font-size:12px;color:#9ca3af;">
                Manage records inline, or open the full portal for templates, monitors, BIND export, and more.
            </p>
        {else}
            <div style="background:#fef3c7; border:1px solid #fbbf24; padding:12px 16px; border-radius:8px; color:#92400e; font-size:13px;">
                Provisioning is pending. Refresh this page in a few seconds.
            </div>
        {/if}
    </div>

    {if $paneldns_usage}
        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); gap:12px;">
            <div style="background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:16px;">
                <div style="font-size:12px; color:#6b7280; text-transform:uppercase; letter-spacing:.05em;">Zones</div>
                <div style="font-size:24px; font-weight:700; color:#111827; margin-top:4px;">
                    {$paneldns_usage.zones|escape}{if $paneldns_limits && $paneldns_limits.zones > 0} <span style="font-size:14px; color:#9ca3af; font-weight:400;">/ {$paneldns_limits.zones|escape}</span>{/if}
                </div>
                {if $paneldns_limits && $paneldns_limits.zones > 0}
                    {assign var=pct value=($paneldns_usage.zones * 100 / max(1, $paneldns_limits.zones))}
                    <div style="background:#f3f4f6; height:6px; border-radius:3px; margin-top:10px; overflow:hidden;">
                        <div style="background:{if $pct >= 90}#dc2626{elseif $pct >= 75}#f59e0b{else}#0891b2{/if}; height:100%; width:{min($pct,100)}%;"></div>
                    </div>
                {/if}
            </div>

            <div style="background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:16px;">
                <div style="font-size:12px; color:#6b7280; text-transform:uppercase; letter-spacing:.05em;">Records</div>
                <div style="font-size:24px; font-weight:700; color:#111827; margin-top:4px;">
                    {$paneldns_usage.records|escape}{if $paneldns_limits && $paneldns_limits.records > 0} <span style="font-size:14px; color:#9ca3af; font-weight:400;">/ {$paneldns_limits.records|escape}</span>{/if}
                </div>
            </div>
        </div>
    {/if}

    {* NS-01 — Nameserver card: always visible so clients know where to point domains. *}
    {if $paneldns_nameservers && $paneldns_nameservers|count > 0}
    <div style="background:#f0fdf4; border:1px solid #bbf7d0; border-radius:10px; padding:16px; margin-top:12px;">
        <div style="font-size:12px; font-weight:600; color:#166534; text-transform:uppercase; letter-spacing:.05em; margin-bottom:8px;">
            &#x1F310; Your Nameservers
        </div>
        <p style="margin:0 0 10px; font-size:13px; color:#374151;">
            Point your domains to these nameservers at your registrar to activate DNS.
        </p>
        {foreach from=$paneldns_nameservers item=ns}
        <div style="font-family:monospace; font-size:14px; font-weight:600; color:#111827; padding:5px 0; border-bottom:1px solid #dcfce7; display:flex; align-items:center; gap:8px;">
            <span>{$ns|escape}</span>
        </div>
        {/foreach}
    </div>
    {/if}

    {* Feature 6 — Zone health widget: surfaces zones that are not active. *}
    {if $paneldns_zones_health && $paneldns_zones_health|count > 0}
        <div style="background:#fff; border:1px solid #fbbf24; border-radius:10px; padding:16px; margin-top:12px;">
            <div style="font-size:13px; font-weight:600; color:#92400e; margin-bottom:8px;">&#9888; Zone Issues Detected</div>
            {foreach from=$paneldns_zones_health item=zone}
            <div style="display:flex; justify-content:space-between; padding:6px 0; border-bottom:1px solid #f3f4f6; font-size:13px;">
                <span style="color:#374151;">{$zone.name|escape}</span>
                <span style="color:#dc2626; font-weight:600;">{$zone.status|escape}</span>
            </div>
            {/foreach}
            <p style="margin:8px 0 0; font-size:12px; color:#9ca3af;">Open the full DNS portal to investigate these zones.</p>
        </div>
    {/if}

</div>
