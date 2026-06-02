{*
  PanelDNS Reseller — SSO redirect.
  Mints a 60-second signed portal login token and forwards the client.
  The redirect_url comes from the trusted PanelDNS API; we still validate
  it client-side before navigating (HTTPS-only guard in the JS).
*}

<div style="text-align:center; padding:60px 20px; font-family:system-ui,-apple-system,sans-serif;">
    <div style="font-size:18px; font-weight:600; color:#111827; margin-bottom:8px;">
        Opening your DNS portal&hellip;
    </div>
    <div style="font-size:14px; color:#6b7280; margin-bottom:24px;">
        You will be logged in automatically.
    </div>
    <div style="font-size:13px; color:#9ca3af;">
        Not redirected?
        {* FIX-H8: only render the href if the URL starts with https:// — prevents open-redirect via javascript: or other schemes *}
        <a href="{if $redirect_url|substr:0:8 == 'https://'}{$redirect_url|escape}{else}#{/if}" style="color:#0891b2; text-decoration:underline;">Click here to continue</a>.
    </div>
</div>

<script>
(function () {
    // SEC: only navigate to HTTPS URLs — SSO tokens are always served over HTTPS.
    var url = {$redirect_url|json_encode};
    if (typeof url === 'string' && /^https:\/\//.test(url)) {
        window.location.href = url;
    }
}());
</script>
