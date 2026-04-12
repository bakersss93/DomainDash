@extends('layouts.app')

@section('content')
    <div style="max-width:1100px;margin:0 auto;">
        <h1 style="font-size:28px;font-weight:700;margin-bottom:8px;">Email Notification Templates</h1>
        <p style="font-size:14px;color:var(--text-muted);margin-bottom:22px;">
            Configure notification triggers and customise the email templates sent for each event.
        </p>

        @if(session('status'))
            <div style="background:var(--success-bg);border:1px solid var(--success-border);border-radius:12px;padding:12px 14px;color:var(--success-text);margin-bottom:14px;">
                {{ session('status') }}
            </div>
        @endif

        @if($errors->any())
            <div style="background:color-mix(in srgb, var(--danger-text) 12%, transparent);border:1px solid color-mix(in srgb, var(--danger-text) 35%, transparent);border-radius:12px;padding:12px 14px;color:var(--danger-text);margin-bottom:14px;">
                <ul style="margin:0;padding-left:18px;">
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ route('admin.notifications.templates.update') }}">
            @csrf
            <div style="background:var(--surface-elevated);border:1px solid var(--border-subtle);border-radius:14px;padding:18px;margin-bottom:18px;">
                <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;margin-bottom:14px;">
                    <h2 style="font-size:18px;font-weight:700;margin:0;">Notification Triggers</h2>
                    <button type="button" class="btn-accent" id="show-variable-library">View placeholder variables</button>
                </div>
                <p style="font-size:13px;color:var(--text-muted);margin:0 0 14px;">Choose which triggers are active, which template they use, and optional override recipients.</p>

                <div class="responsive-table-wrap" style="overflow:auto;">
                    <table style="min-width:780px;">
                        <thead>
                        <tr>
                            <th>Enabled</th>
                            <th>Trigger</th>
                            <th>Template</th>
                            <th>Recipients override</th>
                        </tr>
                        </thead>
                        <tbody>
                        @foreach($triggerLibrary as $triggerKey => $trigger)
                            <tr>
                                <td>
                                    <input type="hidden" name="triggers[{{ $triggerKey }}][enabled]" value="0">
                                    <input type="checkbox"
                                           name="triggers[{{ $triggerKey }}][enabled]"
                                           value="1"
                                           {{ data_get($configuredTriggers, "{$triggerKey}.enabled") ? 'checked' : '' }}>
                                </td>
                                <td>
                                    <div style="font-weight:600;">{{ $trigger['name'] }}</div>
                                    <div style="font-size:12px;color:var(--text-muted);">{{ $trigger['description'] }}</div>
                                </td>
                                <td>
                                    <select name="triggers[{{ $triggerKey }}][template]" style="min-width:180px;">
                                        @foreach($templateLibrary as $templateKey => $template)
                                            <option value="{{ $templateKey }}" {{ data_get($configuredTriggers, "{$triggerKey}.template") === $templateKey ? 'selected' : '' }}>
                                                {{ $template['name'] }}
                                            </option>
                                        @endforeach
                                    </select>
                                </td>
                                <td>
                                    <input type="text"
                                           name="triggers[{{ $triggerKey }}][recipients]"
                                           value="{{ data_get($configuredTriggers, "{$triggerKey}.recipients") }}"
                                           placeholder="ops@example.com, manager@example.com"
                                           style="width:100%;min-width:260px;">
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:16px;">
                @foreach($templateLibrary as $templateKey => $template)
                    <section style="background:var(--surface-elevated);border:1px solid var(--border-subtle);border-radius:14px;padding:16px;">
                        <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:10px;margin-bottom:10px;">
                            <div>
                                <h3 style="font-size:16px;font-weight:700;margin:0 0 4px;">{{ $template['name'] }}</h3>
                                <p style="font-size:12px;color:var(--text-muted);margin:0;">{{ $template['description'] }}</p>
                            </div>
                            <button type="button" class="template-preview-btn" data-target="preview-{{ $templateKey }}" style="border:1px solid var(--border-subtle);background:var(--surface-muted);border-radius:999px;padding:6px 10px;font-size:12px;cursor:pointer;">
                                Preview defaults
                            </button>
                        </div>

                        <label style="display:block;font-size:12px;font-weight:600;margin-bottom:4px;">Subject</label>
                        <input type="text"
                               name="templates[{{ $templateKey }}][subject]"
                               value="{{ data_get($configuredTemplates, "{$templateKey}.subject") }}"
                               style="width:100%;margin-bottom:10px;">

                        <label style="display:block;font-size:12px;font-weight:600;margin-bottom:4px;">Body</label>
                        <textarea name="templates[{{ $templateKey }}][body]" rows="8" style="width:100%;font-family:'Fira Code','Courier New',monospace;">{{ data_get($configuredTemplates, "{$templateKey}.body") }}</textarea>

                        <dialog id="preview-{{ $templateKey }}" style="border:none;border-radius:14px;padding:0;max-width:650px;width:min(92vw,650px);">
                            <div style="padding:18px;background:var(--bg);color:var(--text);border:1px solid var(--border-subtle);border-radius:14px;">
                                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;">
                                    <h4 style="margin:0;font-size:16px;">Default Template Preview</h4>
                                    <button type="button" class="close-preview" data-target="preview-{{ $templateKey }}" style="border:none;background:transparent;font-size:22px;line-height:1;cursor:pointer;color:var(--text-muted);">&times;</button>
                                </div>
                                <p style="font-size:12px;color:var(--text-muted);margin:0 0 8px;">Subject</p>
                                <pre style="white-space:pre-wrap;margin:0 0 12px;padding:10px;border-radius:10px;background:var(--surface-muted);border:1px solid var(--border-subtle);font-size:12px;">{{ $template['subject'] }}</pre>
                                <p style="font-size:12px;color:var(--text-muted);margin:0 0 8px;">Body</p>
                                <pre style="white-space:pre-wrap;margin:0;padding:10px;border-radius:10px;background:var(--surface-muted);border:1px solid var(--border-subtle);font-size:12px;">{{ $template['body'] }}</pre>
                            </div>
                        </dialog>
                    </section>
                @endforeach
            </div>

            <div style="position:sticky;bottom:18px;display:flex;justify-content:flex-end;margin-top:18px;">
                <button type="submit" class="btn-accent" style="padding:10px 18px;">Save Email Notification Settings</button>
            </div>
        </form>
    </div>

    <dialog id="variable-library-modal" style="border:none;border-radius:14px;padding:0;max-width:760px;width:min(92vw,760px);">
        <div style="padding:18px;background:var(--bg);color:var(--text);border:1px solid var(--border-subtle);border-radius:14px;">
            <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;">
                <h2 style="margin:0;font-size:20px;">Placeholder Variable Library</h2>
                <button type="button" id="close-variable-library" style="border:none;background:transparent;font-size:22px;line-height:1;cursor:pointer;color:var(--text-muted);">&times;</button>
            </div>
            <p style="font-size:13px;color:var(--text-muted);margin:8px 0 14px;">Use these placeholders in template subject/body fields. Values are populated automatically when an email is generated.</p>
            <div style="max-height:52vh;overflow:auto;">
                <table>
                    <thead>
                    <tr>
                        <th>Variable</th>
                        <th>Description</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach($variableLibrary as $variable)
                        <tr>
                            <td><code>{{ $variable['key'] }}</code></td>
                            <td>{{ $variable['description'] }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </dialog>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const variableModal = document.getElementById('variable-library-modal');
            const openVariableButton = document.getElementById('show-variable-library');
            const closeVariableButton = document.getElementById('close-variable-library');

            openVariableButton.addEventListener('click', function () {
                variableModal.showModal();
            });

            closeVariableButton.addEventListener('click', function () {
                variableModal.close();
            });

            variableModal.addEventListener('click', function (event) {
                if (event.target === variableModal) {
                    variableModal.close();
                }
            });

            document.querySelectorAll('.template-preview-btn').forEach(function (button) {
                button.addEventListener('click', function () {
                    const target = document.getElementById(button.dataset.target);
                    if (target) {
                        target.showModal();
                    }
                });
            });

            document.querySelectorAll('.close-preview').forEach(function (button) {
                button.addEventListener('click', function () {
                    const target = document.getElementById(button.dataset.target);
                    if (target) {
                        target.close();
                    }
                });
            });

            document.querySelectorAll('dialog').forEach(function (dialog) {
                dialog.addEventListener('click', function (event) {
                    if (event.target === dialog) {
                        dialog.close();
                    }
                });
            });
        });
    </script>
@endsection
