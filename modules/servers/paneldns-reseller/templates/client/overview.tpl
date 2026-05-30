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
                    {$paneldns_usage.zones}{if $paneldns_limits && $paneldns_limits.zones > 0} <span style="font-size:14px; color:#9ca3af; font-weight:400;">/ {$paneldns_limits.zones}</span>{/if}
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
                    {$paneldns_usage.records}{if $paneldns_limits && $paneldns_limits.records > 0} <span style="font-size:14px; color:#9ca3af; font-weight:400;">/ {$paneldns_limits.records}</span>{/if}
                </div>
            </div>
        </div>
    {/if}

</div>
