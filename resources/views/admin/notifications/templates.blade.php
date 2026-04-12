@extends('layouts.app')

@section('content')
    <div style="max-width:1160px;margin:0 auto;">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:14px;flex-wrap:wrap;margin-bottom:16px;">
            <div>
                <h1 style="font-size:30px;font-weight:700;letter-spacing:-0.02em;margin-bottom:6px;">Email Template Library</h1>
                <p style="font-size:14px;color:var(--text-muted);margin:0;">Manage customer and admin notification templates from one place. Click a row to edit template content in a modal.</p>
            </div>
            <button type="button" id="show-variable-library" class="btn-accent">Placeholder Variables</button>
        </div>

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

        <form method="POST" action="{{ route('admin.notifications.templates.update') }}" id="templates-form">
            @csrf

            <div style="background:linear-gradient(180deg,color-mix(in srgb,var(--surface-elevated) 90%,#0f172a 10%),var(--surface-elevated));border:1px solid var(--border-subtle);border-radius:16px;overflow:hidden;margin-bottom:18px;">
                <table style="margin-bottom:0;">
                    <thead>
                    <tr>
                        <th style="width:30%;">Template Name</th>
                        <th style="width:24%;">Trigger</th>
                        <th style="width:16%;">Notification Type</th>
                        <th style="width:20%;">Admin Recipient</th>
                        <th style="width:10%;text-align:right;">Action</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach($configuredTemplates as $templateKey => $template)
                        <tr class="template-row" data-template-key="{{ $templateKey }}" style="cursor:pointer;">
                            <td>
                                <div style="font-weight:700;">{{ $template['name'] }}</div>
                                <div style="font-size:12px;color:var(--text-muted);" data-display-title>{{ $template['title'] }}</div>
                            </td>
                            <td>{{ $template['trigger'] }}</td>
                            <td>
                                <span data-display-group style="display:inline-flex;align-items:center;padding:4px 8px;border-radius:999px;font-size:12px;font-weight:700;background:color-mix(in srgb,var(--accent) 18%,transparent);color:var(--text);text-transform:capitalize;">
                                    {{ $template['group'] }}
                                </span>
                            </td>
                            <td data-display-recipient>
                                @if($template['group'] === 'admin' && filled($template['recipient_email']))
                                    {{ $template['recipient_email'] }}
                                @else
                                    <span style="color:var(--text-muted);">-</span>
                                @endif
                            </td>
                            <td style="text-align:right;">
                                <button type="button" class="open-template-modal" data-template-key="{{ $templateKey }}" style="border:1px solid var(--border-subtle);background:var(--surface-muted);border-radius:999px;padding:7px 12px;font-size:12px;font-weight:700;cursor:pointer;">Edit</button>
                            </td>
                        </tr>

                        <input type="hidden" name="templates[{{ $templateKey }}][title]" value="{{ $template['title'] }}" data-field="title" data-template="{{ $templateKey }}">
                        <input type="hidden" name="templates[{{ $templateKey }}][subject]" value="{{ $template['subject'] }}" data-field="subject" data-template="{{ $templateKey }}">
                        <input type="hidden" name="templates[{{ $templateKey }}][body]" value="{{ $template['body'] }}" data-field="body" data-template="{{ $templateKey }}">
                        <input type="hidden" name="templates[{{ $templateKey }}][group]" value="{{ $template['group'] }}" data-field="group" data-template="{{ $templateKey }}">
                        <input type="hidden" name="templates[{{ $templateKey }}][recipient_email]" value="{{ $template['recipient_email'] }}" data-field="recipient_email" data-template="{{ $templateKey }}">
                    @endforeach
                    </tbody>
                </table>
            </div>

            <div style="display:flex;justify-content:flex-end;">
                <button type="submit" class="btn-accent" style="padding:10px 20px;">Save Template Changes</button>
            </div>
        </form>
    </div>

    <dialog id="template-editor-modal" style="border:none;border-radius:14px;padding:0;max-width:900px;width:min(96vw,900px);">
        <div style="padding:18px;background:var(--bg);color:var(--text);border:1px solid var(--border-subtle);border-radius:14px;">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:10px;margin-bottom:10px;">
                <div>
                    <h2 id="template-modal-name" style="margin:0 0 4px;font-size:20px;">Edit Template</h2>
                    <p id="template-modal-trigger" style="margin:0;color:var(--text-muted);font-size:12px;"></p>
                </div>
                <button type="button" id="close-template-editor" style="border:none;background:transparent;font-size:24px;line-height:1;cursor:pointer;color:var(--text-muted);">&times;</button>
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px;">
                <div>
                    <label style="display:block;font-size:12px;font-weight:700;margin-bottom:4px;">Email title</label>
                    <input type="text" id="modal-title" style="width:100%;">
                </div>
                <div>
                    <label style="display:block;font-size:12px;font-weight:700;margin-bottom:4px;">Notification type</label>
                    <select id="modal-group" style="width:100%;text-transform:capitalize;">
                        @foreach($groupOptions as $groupOption)
                            <option value="{{ $groupOption }}">{{ ucfirst($groupOption) }} notifications</option>
                        @endforeach
                    </select>
                </div>
            </div>

            <div id="modal-recipient-wrapper" style="margin-bottom:12px;display:none;">
                <label style="display:block;font-size:12px;font-weight:700;margin-bottom:4px;">Admin recipient email address</label>
                <input type="email" id="modal-recipient-email" placeholder="ops@example.com" style="width:100%;">
            </div>

            <div style="margin-bottom:12px;">
                <label style="display:block;font-size:12px;font-weight:700;margin-bottom:4px;">Email subject</label>
                <input type="text" id="modal-subject" style="width:100%;">
            </div>

            <div style="margin-bottom:14px;">
                <label style="display:block;font-size:12px;font-weight:700;margin-bottom:4px;">Email body</label>
                <textarea id="modal-body" rows="10" style="width:100%;font-family:'Fira Code','Courier New',monospace;"></textarea>
            </div>

            <div style="display:flex;justify-content:flex-end;gap:10px;">
                <button type="button" id="cancel-template-editor" style="border:1px solid var(--border-subtle);background:var(--surface-muted);border-radius:12px;padding:9px 14px;font-weight:700;cursor:pointer;">Cancel</button>
                <button type="button" id="save-template-editor" class="btn-accent" style="padding:9px 16px;">Update Template</button>
            </div>
        </div>
    </dialog>

    <dialog id="variable-library-modal" style="border:none;border-radius:14px;padding:0;max-width:760px;width:min(92vw,760px);">
        <div style="padding:18px;background:var(--bg);color:var(--text);border:1px solid var(--border-subtle);border-radius:14px;">
            <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;">
                <h2 style="margin:0;font-size:20px;">Placeholder Variable Library</h2>
                <button type="button" id="close-variable-library" style="border:none;background:transparent;font-size:22px;line-height:1;cursor:pointer;color:var(--text-muted);">&times;</button>
            </div>
            <p style="font-size:13px;color:var(--text-muted);margin:8px 0 14px;">Use these placeholders in template title, subject, or body. Values are populated automatically when the email is generated.</p>
            <div style="max-height:52vh;overflow:auto;">
                <table style="margin-bottom:0;">
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
            const editorModal = document.getElementById('template-editor-modal');
            const form = document.getElementById('templates-form');
            const openVariableButton = document.getElementById('show-variable-library');
            const closeVariableButton = document.getElementById('close-variable-library');
            const closeEditorButton = document.getElementById('close-template-editor');
            const cancelEditorButton = document.getElementById('cancel-template-editor');
            const saveEditorButton = document.getElementById('save-template-editor');

            const modalName = document.getElementById('template-modal-name');
            const modalTrigger = document.getElementById('template-modal-trigger');
            const modalTitle = document.getElementById('modal-title');
            const modalGroup = document.getElementById('modal-group');
            const modalRecipientWrapper = document.getElementById('modal-recipient-wrapper');
            const modalRecipientEmail = document.getElementById('modal-recipient-email');
            const modalSubject = document.getElementById('modal-subject');
            const modalBody = document.getElementById('modal-body');

            let activeTemplateKey = null;

            const fieldSelector = function (templateKey, fieldName) {
                return form.querySelector('[data-template="' + templateKey + '"][data-field="' + fieldName + '"]');
            };

            const updateRecipientVisibility = function () {
                if (modalGroup.value === 'admin') {
                    modalRecipientWrapper.style.display = 'block';
                } else {
                    modalRecipientWrapper.style.display = 'none';
                    modalRecipientEmail.value = '';
                }
            };

            const openEditor = function (templateKey) {
                const row = document.querySelector('.template-row[data-template-key="' + templateKey + '"]');
                if (!row) {
                    return;
                }

                activeTemplateKey = templateKey;
                modalName.textContent = row.querySelector('td div').textContent.trim();
                modalTrigger.textContent = row.children[1].textContent.trim();
                modalTitle.value = fieldSelector(templateKey, 'title').value;
                modalSubject.value = fieldSelector(templateKey, 'subject').value;
                modalBody.value = fieldSelector(templateKey, 'body').value;
                modalGroup.value = fieldSelector(templateKey, 'group').value;
                modalRecipientEmail.value = fieldSelector(templateKey, 'recipient_email').value;
                updateRecipientVisibility();
                editorModal.showModal();
            };

            const closeEditor = function () {
                editorModal.close();
                activeTemplateKey = null;
            };

            const syncRowPreview = function (templateKey) {
                const row = document.querySelector('.template-row[data-template-key="' + templateKey + '"]');
                if (!row) {
                    return;
                }

                row.querySelector('[data-display-title]').textContent = fieldSelector(templateKey, 'title').value;

                const group = fieldSelector(templateKey, 'group').value;
                row.querySelector('[data-display-group]').textContent = group;

                const recipientCell = row.querySelector('[data-display-recipient]');
                const recipientValue = fieldSelector(templateKey, 'recipient_email').value;
                if (group === 'admin' && recipientValue.length > 0) {
                    recipientCell.textContent = recipientValue;
                } else {
                    recipientCell.innerHTML = '<span style="color:var(--text-muted);">-</span>';
                }
            };

            openVariableButton.addEventListener('click', function () {
                variableModal.showModal();
            });

            closeVariableButton.addEventListener('click', function () {
                variableModal.close();
            });

            document.querySelectorAll('.template-row, .open-template-modal').forEach(function (element) {
                element.addEventListener('click', function (event) {
                    if (event.target.closest('.open-template-modal') || event.currentTarget.classList.contains('template-row')) {
                        const templateKey = event.target.closest('[data-template-key]')
                            ? event.target.closest('[data-template-key]').dataset.templateKey
                            : event.currentTarget.dataset.templateKey;
                        openEditor(templateKey);
                    }
                });
            });

            modalGroup.addEventListener('change', updateRecipientVisibility);

            closeEditorButton.addEventListener('click', closeEditor);
            cancelEditorButton.addEventListener('click', closeEditor);

            saveEditorButton.addEventListener('click', function () {
                if (!activeTemplateKey) {
                    return;
                }

                fieldSelector(activeTemplateKey, 'title').value = modalTitle.value;
                fieldSelector(activeTemplateKey, 'subject').value = modalSubject.value;
                fieldSelector(activeTemplateKey, 'body').value = modalBody.value;
                fieldSelector(activeTemplateKey, 'group').value = modalGroup.value;
                fieldSelector(activeTemplateKey, 'recipient_email').value = modalGroup.value === 'admin' ? modalRecipientEmail.value : '';
                syncRowPreview(activeTemplateKey);
                closeEditor();
            });

            [variableModal, editorModal].forEach(function (dialog) {
                dialog.addEventListener('click', function (event) {
                    if (event.target === dialog) {
                        dialog.close();
                    }
                });
            });
        });
    </script>
@endsection
