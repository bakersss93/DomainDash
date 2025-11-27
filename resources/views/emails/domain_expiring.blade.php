<div style='font-family:system-ui,Segoe UI,Roboto,Helvetica,Arial,sans-serif'>
  <div style='text-align:center;margin-bottom:16px'>
    <img src='{{ $logoUrl }}' alt='Logo' style='max-height:80px'>
  </div>
  <h2 style='color:#111827'>Domain Expiry Notice</h2>
  <p>Dear {{ $firstName }},</p>
  <p>The domain <strong>{{ $domain }}</strong> will expire on <strong>{{ $expiry }}</strong>.</p>
  <p>Please contact support if you need assistance.</p>
  <hr>
  <small>© {{ date('Y') }} DomainDash</small>
</div>