@extends('layouts.app')

@section('content')
<div class="ui-page">
    <x-ui.page-header title="Set up multi-factor authentication" subtitle="Your account requires MFA before you can continue." />

    <p>Use the setup window to enrol an authenticator app. Nothing else in DomainDash is available until this is complete.</p>
    <p><button type="button" class="ui-btn ui-btn--primary" onclick="document.getElementById('mfaSetupModal').classList.add('is-visible')">Start setup</button></p>
</div>
@endsection
