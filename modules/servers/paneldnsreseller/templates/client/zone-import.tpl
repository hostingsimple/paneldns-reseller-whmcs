{assign var=current_page value="zone-import"}
<div style="max-width:800px;margin:24px auto;">

<div style="display:flex;gap:2px;border-bottom:1px solid #d1d5db;margin-bottom:18px;flex-wrap:wrap;">
    <a href="clientarea.php?action=productdetails&id={$service_id}" style="padding:10px 16px;text-decoration:none;color:#374151;font-weight:500;border:1px solid transparent;border-bottom:none;border-radius:6px 6px 0 0;font-size:14px;">Overview</a>
    <a href="clientarea.php?action=productdetails&id={$service_id}&customAction=zones" style="padding:10px 16px;text-decoration:none;color:#374151;font-weight:500;border:1px solid transparent;border-bottom:none;border-radius:6px 6px 0 0;font-size:14px;">DNS Zones</a>
    <a href="clientarea.php?action=productdetails&id={$service_id}&customAction=zone-create" style="padding:10px 16px;text-decoration:none;color:#374151;font-weight:500;border:1px solid transparent;border-bottom:none;border-radius:6px 6px 0 0;font-size:14px;">Add Zone</a>
    <a href="clientarea.php?action=productdetails&id={$service_id}&customAction=zone-import" style="padding:10px 16px;text-decoration:none;color:#374151;font-weight:500;border:1px solid transparent;border-bottom:none;border-radius:6px 6px 0 0;font-size:14px;background:#fff;border-color:#d1d5db;color:#0891b2;font-weight:600;position:relative;top:1px;">Import (BIND)</a>
</div>
{if $flash}<div style="background:{if $flash.type=='success'}#dcfce7;border:1px solid #86efac;color:#15803d{else}#fee2e2;border:1px solid #fca5a5;color:#991b1b{/if};padding:12px 16px;border-radius:6px;margin-bottom:14px;font-size:13px;">{$flash.msg|escape}</div>{/if}

<div style="background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:24px;">
    <h2 style="margin:0 0 6px;font-size:18px;">Import zone from BIND text</h2>
    <p style="margin:0 0 20px;color:#6b7280;font-size:13px;">
        Paste the contents of a BIND-format zone file (up to {$import_max_kb|escape} KB).
        PanelDNS hands it to the zone's DNS provider, and depending on the provider the
        file can <strong>replace</strong> the records the zone has now.
        <a href="clientarea.php?action=productdetails&id={$service_id}&customAction=zones" style="color:#0891b2;">Export the zone first</a>
        if you want a backup.
    </p>

    {if $zones|@count == 0}
        <div style="background:#fef3c7;border:1px solid #fbbf24;color:#92400e;padding:12px 16px;border-radius:6px;font-size:13px;">
            You need at least one zone to import into.
            <a href="clientarea.php?action=productdetails&id={$service_id}&customAction=zone-create" style="color:#0891b2;">Create one first</a>.
        </div>
    {else}
    <form method="POST" action="clientarea.php?action=productdetails&id={$service_id}&customAction=do-zone-import">
        <input type="hidden" name="csrf" value="{$csrf|escape}">

        <div style="margin-bottom:16px;">
            <label style="display:block;font-size:13px;font-weight:600;color:#374151;margin-bottom:6px;">
                Import into zone <span style="color:#dc2626;">*</span>
            </label>
            <select name="zone_id" required
                    style="width:100%;padding:10px 14px;border:1px solid #d1d5db;border-radius:6px;font-size:14px;background:#fff;">
                <option value="">— Choose a zone —</option>
                {foreach $zones as $z}
                    <option value="{$z.id}">{$z.name|escape}</option>
                {/foreach}
            </select>
        </div>

        <div style="margin-bottom:16px;">
            <label style="display:block;font-size:13px;font-weight:600;color:#374151;margin-bottom:6px;">
                BIND zone text <span style="color:#dc2626;">*</span>
            </label>
            <textarea name="zone_file" rows="14" required maxlength="{$import_max|escape}"
                      placeholder="$ORIGIN example.com.&#10;$TTL 3600&#10;@   IN  SOA  ns1.example.com. hostmaster.example.com. (&#10;        2026052701 ; serial&#10;        ...&#10;)&#10;@   IN  A    1.2.3.4&#10;www IN  CNAME @&#10;@   IN  MX   10 mail.example.com."
                      style="width:100%;padding:12px 14px;border:1px solid #d1d5db;border-radius:6px;font-size:12.5px;font-family:monospace;line-height:1.5;resize:vertical;"></textarea>
            <p style="margin:6px 0 0;font-size:12px;color:#9ca3af;">
                Standard BIND syntax. SOA, NS, A, AAAA, CNAME, MX, TXT, etc. Maximum {$import_max_kb|escape} KB —
                split a larger file and import it in parts.
            </p>
        </div>

        <div style="display:flex;gap:10px;">
            <button type="submit" style="background:#0891b2;color:#fff;padding:10px 20px;border:none;border-radius:6px;font-weight:600;font-size:14px;cursor:pointer;">
                Import Zone File
            </button>
            <a href="clientarea.php?action=productdetails&id={$service_id}&customAction=zones"
               style="padding:10px 20px;color:#6b7280;text-decoration:none;border:1px solid #d1d5db;border-radius:6px;font-size:14px;">
                Cancel
            </a>
        </div>
    </form>
    {/if}
</div>

</div>
