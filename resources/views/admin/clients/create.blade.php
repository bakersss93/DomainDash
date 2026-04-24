@extends('layouts.app')

@section('content')
    <h1 style="font-size:18px;font-weight:600;margin-bottom:16px;">New Client</h1>

    <form method="POST"
          action="{{ route('admin.clients.store') }}"
          style="max-width:640px;">
        @csrf

        <div style="margin-bottom:12px;">
            <label for="business_name" style="display:block;font-size:14px;margin-bottom:4px;">
                Business Name
            </label>
            <input id="business_name" name="business_name" type="text" required
                   value="{{ old('business_name') }}">
            @error('business_name')
                <div style="color:#dc2626;font-size:12px;margin-top:4px;">{{ $message }}</div>
            @enderror
        </div>

        <div style="margin-bottom:12px;">
            <label for="abn" style="display:block;font-size:14px;margin-bottom:4px;">
                ABN
            </label>
            <input id="abn" name="abn" type="text"
                   value="{{ old('abn') }}">
        </div>

        <div style="margin-bottom:12px;">
            <label for="halopsa_reference" style="display:block;font-size:14px;margin-bottom:4px;">
                HaloPSA Reference
            </label>
            <input id="halopsa_reference" name="halopsa_reference" type="text"
                   value="{{ old('halopsa_reference') }}">
        </div>

        <div style="margin-bottom:12px;">
            <label for="itglue_org_name" style="display:block;font-size:14px;margin-bottom:4px;">
                ITGlue Organisation
            </label>

            <div style="display:flex;gap:8px;align-items:center;">
                <input id="itglue_org_name" name="itglue_org_name" type="text"
                       placeholder="Selected ITGlue org name"
                       value="{{ old('itglue_org_name') }}">

                {{-- placeholder button for future ITGlue picker --}}
                <button type="button" class="btn-accent">
                    Select from ITGlue
                </button>
            </div>

            <input type="hidden" id="itglue_org_id" name="itglue_org_id"
                   value="{{ old('itglue_org_id') }}">
        </div>

        {{-- Status as checkbox --}}
        <div style="margin-bottom:16px;">
            <span style="display:block;font-size:14px;margin-bottom:4px;">
                Status
            </span>

            {{-- send 0 when unchecked --}}
            <input type="hidden" name="active" value="0">

            <label style="display:flex;align-items:center;gap:6px;font-size:14px;">
                <input type="checkbox" name="active" value="1"
                       {{ old('active', 1) ? 'checked' : '' }}>
                Active
            </label>
        </div>

        <div style="display:flex;gap:8px;">
            <button type="submit" class="btn-accent">
                Save client
            </button>

            <a href="{{ route('admin.clients.index') }}"
               style="padding:6px 14px;border-radius:4px;border:1px solid #e5e7eb;font-size:14px;text-decoration:none;">
                Cancel
            </a>
        </div>
    </form>
@endsection
