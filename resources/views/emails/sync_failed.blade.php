<div style='font-family:system-ui,Segoe UI,Roboto,Helvetica,Arial,sans-serif'>
  <div style='text-align:center;margin-bottom:16px'>
    <img src='{{ $logoUrl }}' alt='Logo' style='max-height:80px'>
  </div>
  <h2 style='color:#dc2626'>Synergy Sync Failure</h2>
  <p>Admin team,</p>
  <p>A scheduled sync with Synergy Wholesale failed at {{ $when }}.</p>
  <pre>{{ $error }}</pre>
  <hr>
  <small>© {{ date('Y') }} DomainDash</small>
</div>