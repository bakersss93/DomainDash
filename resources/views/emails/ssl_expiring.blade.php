<div style='font-family:system-ui,Segoe UI,Roboto,Helvetica,Arial,sans-serif'>
  <div style='text-align:center;margin-bottom:16px'>
    <img src='{{ $logoUrl }}' alt='Logo' style='max-height:80px'>
  </div>
  <h2 style='color:#111827'>SSL Certificate Expiry</h2>
  <p>Dear {{ $firstName }},</p>
  <p>The SSL for <strong>{{ $commonName }}</strong> expires on <strong>{{ $expiry }}</strong>.</p>
  <p>Customers see full certificate details in the portal.</p>
  <hr>
  <small>© {{ date('Y') }} DomainDash</small>
</div>