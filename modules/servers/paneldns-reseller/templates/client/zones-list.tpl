{assign var=current_page value="zones"}
<div style="max-width:1000px;margin:24px auto;">

{* ── DNS nav (inlined; Smarty include paths inside WHMCS modules are fragile) *}
<div style="display:flex;gap:2px;border-bottom:1px solid #d1d5db;margin-bottom:18px;flex-wrap:wrap;">
    <a href="clientarea.php?action=productdetails&id={$service_id}" style="padding:10px 16px;text-decoration:none;color:#374151;font-weight:500;border:1px solid transparent;border-bottom:none;border-radius:6px 6px 0 0;font-size:14px;">Overview</a>
    <a href="clientarea.php?action=productdetails&id={$service_id}&modop=custom&a=zones" style="padding:10px 16px;text-decoration:none;color:#374151;font-weight:500;border:1px solid transparent;border-bottom:none;border-radius:6px 6px 0 0;font-size:14px;background:#fff;border-color:#d1d5db;color:#0891b2;font-weight:600;position:relative;top:1px;">DNS Zones</a>
    <a href="clientarea.php?action=productdetails&id={$service_id}&modop=custom&a=zone-create" style="padding:10px 16px;text-decoration:none;color:#374151;font-weight:500;border:1px solid transparent;border-bottom:none;border-radius:6px 6px 0 0;font-size:14px;">Add Zone</a>
    <a href="clientarea.php?action=productdetails&id={$service_id}&modop=custom&a=zone-import" style="padding:10px 16px;text-decoration:none;color:#374151;font-weight:500;border:1px solid transparent;border-bottom:none;border-radius:6px 6px 0 0;font-size:14px;">Import (BIND)</a>
</div>
{if $flash}<div style="background:{if $flash.type=='success'}#dcfce7;border:1px solid #86efac;color:#15803d{else}#fee2e2;border:1px solid #fca5a5;color:#991b1b{/if};padding:12px 16px;border-radius:6px;margin-bottom:14px;font-size:13px;">{$flash.msg|escape}</div>{/if}

{if $error}
<div style="background:#fee2e2;border:1px solid #fca5a5;color:#991b1b;padding:12px 16px;border-radius:6px;margin-bottom:14px;font-size:13px;">{$error|escape}</div>
{/if}

<div style="background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:20px;">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;">
        <h2 style="margin:0;font-size:18px;color:#111827;">Your DNS Zones</h2>
        <a href="clientarea.php?action=productdetails&id={$service_id}&modop=custom&a=zone-create"
           style="background:#0891b2;color:#fff;padding:8px 14px;border-radius:6px;text-decoration:none;font-weight:600;font-size:13px;">
            + Add Zone
        </a>
    </div>

    {if $zones|@count == 0}
        <div style="text-align:center;padding:40px 0;color:#9ca3af;font-size:14px;">
            No zones yet. <a href="clientarea.php?action=productdetails&id={$service_id}&modop=custom&a=zone-create" style="color:#0891b2;">Add your first zone</a>
            or <a href="clientarea.php?action=productdetails&id={$service_id}&modop=custom&a=zone-import" style="color:#0891b2;">import from a BIND file</a>.
        </div>
    {else}
        <table style="width:100%;border-collapse:collapse;font-size:14px;">
            <thead>
                <tr style="border-bottom:1px solid #e5e7eb;">
                    <th style="text-align:left;padding:10px 12px;font-size:12px;text-transform:uppercase;letter-spacing:.04em;color:#6b7280;">Zone</th>
                    <th style="text-align:left;padding:10px 12px;font-size:12px;text-transform:uppercase;letter-spacing:.04em;color:#6b7280;">Status</th>
                    <th style="text-align:right;padding:10px 12px;font-size:12px;text-transform:uppercase;letter-spacing:.04em;color:#6b7280;">Last sync</th>
                    <th style="padding:10px 12px;"></th>
                </tr>
            </thead>
            <tbody>
            {foreach $zones as $zone}
                <tr style="border-bottom:1px solid #f3f4f6;">
                    <td style="padding:12px;">
                        <a href="clientarea.php?action=productdetails&id={$service_id}&modop=custom&a=records&zone={$zone.id}"
                           style="color:#0891b2;text-decoration:none;font-weight:600;">
                            {$zone.name|escape}
                        </a>
                    </td>
                    <td style="padding:12px;">
                        {if $zone.status=='active'}
                            <span style="display:inline-block;padding:2px 8px;border-radius:10px;background:#dcfce7;color:#15803d;font-size:11px;font-weight:600;">active</span>
                        {else}
                            <span style="display:inline-block;padding:2px 8px;border-radius:10px;background:#f3f4f6;color:#6b7280;font-size:11px;font-weight:600;">{$zone.status|escape}</span>
                        {/if}
                    </td>
                    <td style="padding:12px;text-align:right;color:#6b7280;font-size:12.5px;">{if $zone.last_synced_at}{$zone.last_synced_at|escape}{else}—{/if}</td>
                    <td style="padding:12px;text-align:right;">
                        <a href="clientarea.php?action=productdetails&id={$service_id}&modop=custom&a=records&zone={$zone.id}" style="color:#0891b2;text-decoration:none;font-size:13px;font-weight:500;margin-right:10px;">Manage</a>
                        <form method="POST" action="clientarea.php?action=productdetails&id={$service_id}&modop=custom&a=do-zone-delete" style="display:inline;"
                              onsubmit="return confirm('Delete the entire zone ' + {$zone.name|json_encode} + '? This cannot be undone.');">
                            <input type="hidden" name="csrf" value="{$csrf|escape}">
                            <input type="hidden" name="zone_id" value="{$zone.id}">
                            <button type="submit" style="background:none;border:none;color:#dc2626;font-size:13px;cursor:pointer;font-weight:500;">Delete</button>
                        </form>
                    </td>
                </tr>
            {/foreach}
            </tbody>
        </table>
    {/if}
</div>

<div style="margin-top:14px;text-align:center;font-size:12px;color:#9ca3af;">
    Need advanced features like monitors, templates, or DNSSEC?
    <a href="clientarea.php?action=productdetails&id={$service_id}&modop=custom&a=sso" style="color:#0891b2;">Open the full DNS portal →</a>
</div>

</div>
