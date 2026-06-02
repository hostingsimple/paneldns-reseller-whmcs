{assign var=current_page value="records"}
<div style="max-width:1100px;margin:24px auto;">

<div style="display:flex;gap:2px;border-bottom:1px solid #d1d5db;margin-bottom:18px;flex-wrap:wrap;">
    <a href="clientarea.php?action=productdetails&id={$service_id}" style="padding:10px 16px;text-decoration:none;color:#374151;font-weight:500;border:1px solid transparent;border-bottom:none;border-radius:6px 6px 0 0;font-size:14px;">Overview</a>
    <a href="clientarea.php?action=productdetails&id={$service_id}&modop=custom&a=zones" style="padding:10px 16px;text-decoration:none;color:#374151;font-weight:500;border:1px solid transparent;border-bottom:none;border-radius:6px 6px 0 0;font-size:14px;background:#fff;border-color:#d1d5db;color:#0891b2;font-weight:600;position:relative;top:1px;">DNS Zones</a>
    <a href="clientarea.php?action=productdetails&id={$service_id}&modop=custom&a=zone-create" style="padding:10px 16px;text-decoration:none;color:#374151;font-weight:500;border:1px solid transparent;border-bottom:none;border-radius:6px 6px 0 0;font-size:14px;">Add Zone</a>
    <a href="clientarea.php?action=productdetails&id={$service_id}&modop=custom&a=zone-import" style="padding:10px 16px;text-decoration:none;color:#374151;font-weight:500;border:1px solid transparent;border-bottom:none;border-radius:6px 6px 0 0;font-size:14px;">Import (BIND)</a>
</div>
{if $flash}<div style="background:{if $flash.type=='success'}#dcfce7;border:1px solid #86efac;color:#15803d{else}#fee2e2;border:1px solid #fca5a5;color:#991b1b{/if};padding:12px 16px;border-radius:6px;margin-bottom:14px;font-size:13px;">{$flash.msg|escape}</div>{/if}

{* Find the record being edited (if any), for pre-filling the form below *}
{assign var=edit_record value=null}
{if $edit_record_id}
    {foreach $records as $r}
        {if $r.id == $edit_record_id}{assign var=edit_record value=$r}{/if}
    {/foreach}
{/if}

<div style="background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:20px;margin-bottom:14px;">
    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;">
        <h2 style="margin:0;font-size:18px;color:#111827;">
            <a href="clientarea.php?action=productdetails&id={$service_id}&modop=custom&a=zones" style="color:#9ca3af;text-decoration:none;font-weight:400;">DNS Zones</a>
            <span style="color:#d1d5db;margin:0 6px;">›</span>
            <span style="font-family:monospace;">{$zone.name|escape}</span>
        </h2>
        <div style="display:flex;align-items:center;gap:12px;">
            <span style="font-size:12px;color:#9ca3af;">{$records|@count} record{if $records|@count != 1}s{/if}</span>
            {* EXPORT-01: BIND download link — GET, streams file + exits *}
            <a href="clientarea.php?action=productdetails&id={$service_id}&modop=custom&a=zone-export&zone={$zone.id}"
               style="font-size:12.5px;color:#6b7280;text-decoration:none;border:1px solid #d1d5db;padding:4px 10px;border-radius:5px;font-weight:500;"
               title="Download this zone as a BIND-format text file">
                &#8615; Export (BIND)
            </a>
        </div>
    </div>
</div>

{if $error}
<div style="background:#fee2e2;border:1px solid #fca5a5;color:#991b1b;padding:12px 16px;border-radius:6px;margin-bottom:14px;font-size:13px;">{$error|escape}</div>
{/if}

{* ── Add / edit form ─────────────────────────────────────────────────── *}
<div style="background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:20px;margin-bottom:14px;">
    <h3 style="margin:0 0 14px;font-size:15px;color:#111827;">
        {if $edit_record}Edit record{else}Add a record{/if}
    </h3>
    <form method="POST" action="clientarea.php?action=productdetails&id={$service_id}&modop=custom&a={if $edit_record}do-record-update{else}do-record-create{/if}">
        <input type="hidden" name="csrf" value="{$csrf|escape}">
        <input type="hidden" name="zone_id" value="{$zone.id}">
        {if $edit_record}<input type="hidden" name="record_id" value="{$edit_record.id}">{/if}

        <div style="display:grid;grid-template-columns:1fr 130px 2fr 100px 100px;gap:10px;align-items:end;">
            <div>
                <label style="display:block;font-size:11.5px;text-transform:uppercase;letter-spacing:.04em;color:#6b7280;margin-bottom:4px;">Name</label>
                <input type="text" name="name" required
                       value="{if $edit_record}{$edit_record.name|escape}{else}@{/if}"
                       placeholder="@, www, mail, …"
                       style="width:100%;padding:8px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;font-family:monospace;">
            </div>
            <div>
                <label style="display:block;font-size:11.5px;text-transform:uppercase;letter-spacing:.04em;color:#6b7280;margin-bottom:4px;">Type</label>
                <select name="type" required style="width:100%;padding:8px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;background:#fff;">
                    {assign var=t value=$edit_record.type|default:"A"}
                    {foreach from=["A","AAAA","CNAME","MX","TXT","NS","SRV","CAA","PTR","SSHFP","TLSA","NAPTR","HTTPS"] item=opt}
                        <option value="{$opt}" {if $opt == $t}selected{/if}>{$opt}</option>
                    {/foreach}
                </select>
            </div>
            <div>
                <label style="display:block;font-size:11.5px;text-transform:uppercase;letter-spacing:.04em;color:#6b7280;margin-bottom:4px;">Content</label>
                <input type="text" name="content" required
                       value="{if $edit_record}{$edit_record.content|escape}{/if}"
                       placeholder="1.2.3.4 / mail.example.com / v=spf1 ~all"
                       style="width:100%;padding:8px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;font-family:monospace;">
            </div>
            <div>
                <label style="display:block;font-size:11.5px;text-transform:uppercase;letter-spacing:.04em;color:#6b7280;margin-bottom:4px;">TTL (s)</label>
                <input type="number" name="ttl" min="60" max="86400"
                       value="{if $edit_record}{$edit_record.ttl|escape}{else}3600{/if}"
                       style="width:100%;padding:8px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;">
            </div>
            <div>
                <label style="display:block;font-size:11.5px;text-transform:uppercase;letter-spacing:.04em;color:#6b7280;margin-bottom:4px;">Priority</label>
                <input type="number" name="priority" min="0" max="65535"
                       value="{if $edit_record && $edit_record.priority !== null}{$edit_record.priority|escape}{/if}"
                       placeholder="MX/SRV only"
                       style="width:100%;padding:8px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;">
            </div>
        </div>

        <div style="margin-top:14px;display:flex;gap:8px;">
            <button type="submit" style="background:#0891b2;color:#fff;padding:8px 18px;border:none;border-radius:6px;font-weight:600;font-size:13px;cursor:pointer;">
                {if $edit_record}Save changes{else}Add record{/if}
            </button>
            {if $edit_record}
            <a href="clientarea.php?action=productdetails&id={$service_id}&modop=custom&a=records&zone={$zone.id}"
               style="padding:8px 18px;color:#6b7280;text-decoration:none;border:1px solid #d1d5db;border-radius:6px;font-size:13px;">Cancel edit</a>
            {/if}
        </div>
    </form>
</div>

{* ── Records table ────────────────────────────────────────────────────── *}
<div style="background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:0;">
    <table style="width:100%;border-collapse:collapse;font-size:13px;">
        <thead>
            <tr style="border-bottom:1px solid #e5e7eb;background:#f9fafb;">
                <th style="text-align:left;padding:10px 12px;font-size:11px;text-transform:uppercase;letter-spacing:.04em;color:#6b7280;">Name</th>
                <th style="text-align:left;padding:10px 12px;font-size:11px;text-transform:uppercase;letter-spacing:.04em;color:#6b7280;">Type</th>
                <th style="text-align:left;padding:10px 12px;font-size:11px;text-transform:uppercase;letter-spacing:.04em;color:#6b7280;">Content</th>
                <th style="text-align:right;padding:10px 12px;font-size:11px;text-transform:uppercase;letter-spacing:.04em;color:#6b7280;">TTL</th>
                <th style="text-align:right;padding:10px 12px;font-size:11px;text-transform:uppercase;letter-spacing:.04em;color:#6b7280;">Priority</th>
                <th style="padding:10px 12px;"></th>
            </tr>
        </thead>
        <tbody>
        {if $records|@count == 0}
            <tr><td colspan="6" style="text-align:center;padding:36px 0;color:#9ca3af;font-size:13.5px;">No records yet. Add one above.</td></tr>
        {else}
            {foreach $records as $r}
            <tr style="border-bottom:1px solid #f3f4f6;{if $edit_record_id == $r.id} background:#fef9c3;{/if}">
                <td style="padding:10px 12px;font-family:monospace;">{$r.name|escape}</td>
                <td style="padding:10px 12px;"><span style="display:inline-block;padding:1px 7px;border-radius:8px;background:#e5e7eb;color:#374151;font-size:11px;font-weight:600;font-family:monospace;">{$r.type|escape}</span></td>
                <td style="padding:10px 12px;font-family:monospace;word-break:break-all;color:#374151;">{$r.content|escape}</td>
                <td style="padding:10px 12px;text-align:right;color:#6b7280;">{$r.ttl|escape}</td>
                <td style="padding:10px 12px;text-align:right;color:#6b7280;">{if $r.priority !== null}{$r.priority|escape}{else}—{/if}</td>
                <td style="padding:10px 12px;text-align:right;white-space:nowrap;">
                    <a href="clientarea.php?action=productdetails&id={$service_id}&modop=custom&a=records&zone={$zone.id}&edit={$r.id}" style="color:#0891b2;text-decoration:none;font-size:12.5px;font-weight:500;margin-right:10px;">Edit</a>
                    <form method="POST" action="clientarea.php?action=productdetails&id={$service_id}&modop=custom&a=do-record-delete" style="display:inline;"
                          onsubmit="return confirm('Delete this ' + {$r.type|json_encode} + ' record?');">
                        <input type="hidden" name="csrf" value="{$csrf|escape}">
                        <input type="hidden" name="zone_id" value="{$zone.id}">
                        <input type="hidden" name="record_id" value="{$r.id}">
                        <button type="submit" style="background:none;border:none;color:#dc2626;cursor:pointer;font-size:12.5px;font-weight:500;">Delete</button>
                    </form>
                </td>
            </tr>
            {/foreach}
        {/if}
        </tbody>
    </table>
</div>

{* NS-CARD-01: always show nameservers on the records page so clients know
   where to point their domains without navigating back to the overview. *}
{if $nameservers && $nameservers|@count > 0}
<div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:10px;padding:14px 16px;margin-top:14px;">
    <div style="font-size:11.5px;font-weight:600;color:#166534;text-transform:uppercase;letter-spacing:.05em;margin-bottom:8px;">
        &#x1F310; Point your domain to these nameservers
    </div>
    {foreach from=$nameservers item=ns}
    <div style="font-family:monospace;font-size:13.5px;font-weight:600;color:#111827;padding:3px 0;">{$ns|escape}</div>
    {/foreach}
</div>
{/if}

<div style="margin-top:14px;text-align:center;font-size:12px;color:#9ca3af;">
    Need advanced features?
    <a href="clientarea.php?action=productdetails&id={$service_id}&modop=custom&a=sso" style="color:#0891b2;">Open in full DNS portal →</a>
</div>

</div>
