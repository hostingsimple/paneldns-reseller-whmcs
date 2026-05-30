{assign var=current_page value="zone-create"}
<div style="max-width:600px;margin:24px auto;">

<div style="display:flex;gap:2px;border-bottom:1px solid #d1d5db;margin-bottom:18px;flex-wrap:wrap;">
    <a href="clientarea.php?action=productdetails&id={$service_id}" style="padding:10px 16px;text-decoration:none;color:#374151;font-weight:500;border:1px solid transparent;border-bottom:none;border-radius:6px 6px 0 0;font-size:14px;">Overview</a>
    <a href="clientarea.php?action=productdetails&id={$service_id}&modop=custom&a=zones" style="padding:10px 16px;text-decoration:none;color:#374151;font-weight:500;border:1px solid transparent;border-bottom:none;border-radius:6px 6px 0 0;font-size:14px;">DNS Zones</a>
    <a href="clientarea.php?action=productdetails&id={$service_id}&modop=custom&a=zone-create" style="padding:10px 16px;text-decoration:none;color:#374151;font-weight:500;border:1px solid transparent;border-bottom:none;border-radius:6px 6px 0 0;font-size:14px;background:#fff;border-color:#d1d5db;color:#0891b2;font-weight:600;position:relative;top:1px;">Add Zone</a>
    <a href="clientarea.php?action=productdetails&id={$service_id}&modop=custom&a=zone-import" style="padding:10px 16px;text-decoration:none;color:#374151;font-weight:500;border:1px solid transparent;border-bottom:none;border-radius:6px 6px 0 0;font-size:14px;">Import (BIND)</a>
</div>
{if $flash}<div style="background:{if $flash.type=='success'}#dcfce7;border:1px solid #86efac;color:#15803d{else}#fee2e2;border:1px solid #fca5a5;color:#991b1b{/if};padding:12px 16px;border-radius:6px;margin-bottom:14px;font-size:13px;">{$flash.msg|escape}</div>{/if}

<div style="background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:24px;">
    <h2 style="margin:0 0 6px;font-size:18px;">Add a new zone</h2>
    <p style="margin:0 0 20px;color:#6b7280;font-size:13px;">
        Provide your bare domain name (e.g. <code>example.com</code>, not
        <code>www.example.com</code>). You can add records after creating it.
    </p>

    <form method="POST" action="clientarea.php?action=productdetails&id={$service_id}&modop=custom&a=do-zone-create">
        <input type="hidden" name="csrf" value="{$csrf|escape}">

        <div style="margin-bottom:16px;">
            <label style="display:block;font-size:13px;font-weight:600;color:#374151;margin-bottom:6px;">
                Zone name <span style="color:#dc2626;">*</span>
            </label>
            <input type="text" name="name" required autofocus
                   placeholder="example.com"
                   pattern="[a-zA-Z0-9]([a-zA-Z0-9_\-]|\.[a-zA-Z0-9])*"
                   style="width:100%;padding:10px 14px;border:1px solid #d1d5db;border-radius:6px;font-size:14px;font-family:monospace;">
            <p style="margin:6px 0 0;font-size:12px;color:#9ca3af;">
                Lowercase letters, digits, hyphens. No protocol, no trailing dot.
            </p>
        </div>

        <div style="display:flex;gap:10px;">
            <button type="submit" style="background:#0891b2;color:#fff;padding:10px 20px;border:none;border-radius:6px;font-weight:600;font-size:14px;cursor:pointer;">
                Create Zone
            </button>
            <a href="clientarea.php?action=productdetails&id={$service_id}&modop=custom&a=zones"
               style="padding:10px 20px;color:#6b7280;text-decoration:none;border:1px solid #d1d5db;border-radius:6px;font-size:14px;">
                Cancel
            </a>
        </div>
    </form>
</div>

</div>
