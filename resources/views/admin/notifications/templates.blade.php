@extends('layouts.app')

@section('content')
    <div style="max-width:1180px;margin:0 auto;">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:14px;flex-wrap:wrap;margin-bottom:16px;">
            <div>
                <h1 style="font-size:30px;font-weight:700;letter-spacing:-0.02em;margin-bottom:6px;">Email Notification Builder</h1>
                <p style="font-size:14px;color:var(--text-muted);margin:0;">Triggers are now database-driven. Create event notifications first, then tune templates from the library below.</p>
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

        <section style="background:linear-gradient(180deg,color-mix(in srgb,var(--surface-elevated) 82%,#0b1220 18%),var(--surface-elevated));border:1px solid var(--border-subtle);border-radius:16px;overflow:hidden;margin-bottom:20px;">
            <div style="padding:14px 16px;display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;border-bottom:1px solid var(--border-subtle);">
                <div>
                    <h2 style="margin:0;font-size:18px;">Notification Triggers</h2>
                    <p style="margin:4px 0 0;color:var(--text-muted);font-size:12px;">Create trigger rules (for example: domain expiry + days before event).</p>
                </div>
                <button type="button" id="open-trigger-modal" class="btn-accent">Create Notification</button>
            </div>
            <div style="overflow:auto;">
                <table style="margin-bottom:0;min-width:900px;">
                    <thead>
                    <tr>
                        <th>Name</th>
                        <th>Event</th>
                        <th>Days Before</th>
                        <th>Audience</th>
                        <th>Template</th>
                        <th>HaloPSA Ticket</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse($triggers as $trigger)
                        <tr>
                            <td>{{ $trigger->name }}</td>
                            <td>{{ $eventOptions[$trigger->event_key] ?? $trigger->event_key }}</td>
                            <td>{{ $trigger->days_before ?? '-' }}</td>
                            <td style="text-transform:capitalize;">{{ $trigger->audience }}</td>
                            <td>{{ $trigger->emailTemplate?->name ?? '-' }}</td>
                            <td>
                                @if($trigger->admin_create_halo_ticket)
                                    {{ $trigger->halo_ticket_board ?: 'Board n/a' }} / {{ $trigger->halo_ticket_type ?: 'Type n/a' }}
                                @else
                                    -
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" style="text-align:center;color:var(--text-muted);">No notification triggers configured yet.</td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <section style="background:linear-gradient(180deg,color-mix(in srgb,var(--surface-elevated) 90%,#0f172a 10%),var(--surface-elevated));border:1px solid var(--border-subtle);border-radius:16px;overflow:hidden;">
            <div style="padding:14px 16px;border-bottom:1px solid var(--border-subtle);">
                <h2 style="margin:0;font-size:18px;">Template Library</h2>
            </div>
            <table style="margin-bottom:0;">
                <thead>
                <tr>
                    <th style="width:30%;">Template</th>
                    <th style="width:20%;">Default Event</th>
                    <th style="width:15%;">Audience</th>
                    <th style="width:25%;">Admin Recipient</th>
                    <th style="width:10%;text-align:right;">Action</th>
                </tr>
                </thead>
                <tbody>
                @foreach($templates as $template)
                    <tr>
                        <td>
                            <div style="font-weight:700;">{{ $template->name }}</div>
                            <div style="font-size:12px;color:var(--text-muted);">{{ $template->title }}</div>
                        </td>
                        <td>{{ $eventOptions[$template->trigger_event] ?? '-' }}</td>
                        <td style="text-transform:capitalize;">{{ $template->audience }}</td>
                        <td>{{ $template->admin_recipient_email ?: '-' }}</td>
                        <td style="text-align:right;">
                            <button type="button"
                                    class="open-template-modal"
                                    data-id="{{ $template->id }}"
                                    data-name="{{ $template->name }}"
                                    data-title="{{ $template->title }}"
                                    data-subject="{{ $template->subject }}"
                                    data-body="{{ $template->body }}"
                                    data-audience="{{ $template->audience }}"
                                    data-recipient="{{ $template->admin_recipient_email }}"
                                    data-action="{{ route('admin.notifications.templates.template.update', $template) }}"
                                    style="border:1px solid var(--border-subtle);background:var(--surface-muted);border-radius:999px;padding:7px 12px;font-size:12px;font-weight:700;cursor:pointer;">
                                Edit
                            </button>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </section>
    </div>

    <dialog id="template-editor-modal" style="border:none;border-radius:14px;padding:0;max-width:900px;width:min(96vw,900px);">
        <form method="POST" id="template-editor-form" style="padding:18px;background:var(--bg);color:var(--text);border:1px solid var(--border-subtle);border-radius:14px;">
            @csrf
            <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:10px;margin-bottom:10px;">
                <h2 id="template-modal-name" style="margin:0;font-size:20px;">Edit Template</h2>
                <button type="button" id="close-template-editor" style="border:none;background:transparent;font-size:24px;line-height:1;cursor:pointer;color:var(--text-muted);">&times;</button>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px;">
                <div>
                    <label style="display:block;font-size:12px;font-weight:700;margin-bottom:4px;">Email title</label>
                    <input type="text" id="modal-title" name="title" style="width:100%;">
                </div>
                <div>
                    <label style="display:block;font-size:12px;font-weight:700;margin-bottom:4px;">Audience</label>
                    <select id="modal-audience" name="audience" style="width:100%;">
                        @foreach($audienceOptions as $audience)
                            <option value="{{ $audience }}">{{ ucfirst($audience) }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <div id="modal-template-recipient-wrapper" style="margin-bottom:12px;display:none;">
                <label style="display:block;font-size:12px;font-weight:700;margin-bottom:4px;">Admin recipient email address</label>
                <input type="email" id="modal-template-recipient" name="admin_recipient_email" style="width:100%;">
            </div>
            <div style="margin-bottom:12px;">
                <label style="display:block;font-size:12px;font-weight:700;margin-bottom:4px;">Email subject</label>
                <input type="text" id="modal-subject" name="subject" style="width:100%;">
            </div>
            <div style="margin-bottom:14px;">
                <label style="display:block;font-size:12px;font-weight:700;margin-bottom:4px;">Email body</label>
                <textarea id="modal-body" rows="10" name="body" style="width:100%;font-family:'Fira Code','Courier New',monospace;"></textarea>
            </div>
            <div style="display:flex;justify-content:flex-end;gap:10px;">
                <button type="button" id="cancel-template-editor" style="border:1px solid var(--border-subtle);background:var(--surface-muted);border-radius:12px;padding:9px 14px;font-weight:700;cursor:pointer;">Cancel</button>
                <button type="submit" class="btn-accent" style="padding:9px 16px;">Update Template</button>
            </div>
        </form>
    </dialog>

    <dialog id="trigger-editor-modal" style="border:none;border-radius:14px;padding:0;max-width:920px;width:min(96vw,920px);">
        <form method="POST" action="{{ route('admin.notifications.triggers.store') }}" id="trigger-editor-form" style="padding:18px;background:var(--bg);color:var(--text);border:1px solid var(--border-subtle);border-radius:14px;">
            @csrf
            <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:10px;margin-bottom:10px;">
                <h2 style="margin:0;font-size:20px;">Create Notification Trigger</h2>
                <button type="button" id="close-trigger-editor" style="border:none;background:transparent;font-size:24px;line-height:1;cursor:pointer;color:var(--text-muted);">&times;</button>
            </div>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:12px;margin-bottom:12px;">
                <div>
                    <label style="display:block;font-size:12px;font-weight:700;margin-bottom:4px;">Notification name</label>
                    <input type="text" name="name" placeholder="Domain expiry at 30 days" required style="width:100%;">
                </div>
                <div>
                    <label style="display:block;font-size:12px;font-weight:700;margin-bottom:4px;">Trigger event</label>
                    <select name="event_key" required style="width:100%;">
                        @foreach($eventOptions as $eventKey => $eventLabel)
                            <option value="{{ $eventKey }}">{{ $eventLabel }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label style="display:block;font-size:12px;font-weight:700;margin-bottom:4px;">Days left (optional)</label>
                    <input type="number" min="0" max="365" name="days_before" placeholder="30" style="width:100%;">
                </div>
                <div>
                    <label style="display:block;font-size:12px;font-weight:700;margin-bottom:4px;">Audience</label>
                    <select name="audience" id="trigger-audience" required style="width:100%;">
                        @foreach($audienceOptions as $audience)
                            <option value="{{ $audience }}">{{ ucfirst($audience) }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label style="display:block;font-size:12px;font-weight:700;margin-bottom:4px;">Template to use</label>
                    <select name="email_template_id" required style="width:100%;">
                        @foreach($templates as $template)
                            <option value="{{ $template->id }}">{{ $template->name }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <div id="halopsa-block" style="margin-bottom:14px;padding:12px;border:1px dashed var(--border-subtle);border-radius:12px;display:none;">
                <label style="display:flex;align-items:center;gap:8px;margin-bottom:10px;">
                    <input type="hidden" name="admin_create_halo_ticket" value="0">
                    <input type="checkbox" name="admin_create_halo_ticket" id="admin_create_halo_ticket" value="1">
                    <span style="font-size:13px;font-weight:700;">Create HaloPSA ticket for this admin notification</span>
                </label>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;" id="halopsa-fields">
                    <div>
                        <label style="display:block;font-size:12px;font-weight:700;margin-bottom:4px;">HaloPSA board</label>
                        <input type="text" name="halo_ticket_board" placeholder="Service Desk" style="width:100%;">
                    </div>
                    <div>
                        <label style="display:block;font-size:12px;font-weight:700;margin-bottom:4px;">HaloPSA ticket type</label>
                        <input type="text" name="halo_ticket_type" placeholder="Alert" style="width:100%;">
                    </div>
                </div>
            </div>
            <div style="display:flex;justify-content:flex-end;gap:10px;">
                <button type="button" id="cancel-trigger-editor" style="border:1px solid var(--border-subtle);background:var(--surface-muted);border-radius:12px;padding:9px 14px;font-weight:700;cursor:pointer;">Cancel</button>
                <button type="submit" class="btn-accent" style="padding:9px 16px;">Create Trigger</button>
            </div>
        </form>
    </dialog>

    <dialog id="variable-library-modal" style="border:none;border-radius:14px;padding:0;max-width:760px;width:min(92vw,760px);">
        <div style="padding:18px;background:var(--bg);color:var(--text);border:1px solid var(--border-subtle);border-radius:14px;">
            <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;">
                <h2 style="margin:0;font-size:20px;">Placeholder Variable Library</h2>
                <button type="button" id="close-variable-library" style="border:none;background:transparent;font-size:22px;line-height:1;cursor:pointer;color:var(--text-muted);">&times;</button>
            </div>
            <div style="max-height:52vh;overflow:auto;margin-top:12px;">
                <table style="margin-bottom:0;">
                    <thead><tr><th>Variable</th><th>Description</th></tr></thead>
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
            const templateModal = document.getElementById('template-editor-modal');
            const triggerModal = document.getElementById('trigger-editor-modal');

            document.getElementById('show-variable-library').addEventListener('click', function () { variableModal.showModal(); });
            document.getElementById('close-variable-library').addEventListener('click', function () { variableModal.close(); });
            document.getElementById('open-trigger-modal').addEventListener('click', function () { triggerModal.showModal(); });
            document.getElementById('close-trigger-editor').addEventListener('click', function () { triggerModal.close(); });
            document.getElementById('cancel-trigger-editor').addEventListener('click', function () { triggerModal.close(); });
            document.getElementById('close-template-editor').addEventListener('click', function () { templateModal.close(); });
            document.getElementById('cancel-template-editor').addEventListener('click', function () { templateModal.close(); });

            const modalAudience = document.getElementById('modal-audience');
            const modalTemplateRecipientWrapper = document.getElementById('modal-template-recipient-wrapper');
            const modalTemplateRecipient = document.getElementById('modal-template-recipient');

            const updateTemplateRecipientVisibility = function () {
                if (modalAudience.value === 'admin') {
                    modalTemplateRecipientWrapper.style.display = 'block';
                } else {
                    modalTemplateRecipientWrapper.style.display = 'none';
                    modalTemplateRecipient.value = '';
                }
            };

            modalAudience.addEventListener('change', updateTemplateRecipientVisibility);

            document.querySelectorAll('.open-template-modal').forEach(function (button) {
                button.addEventListener('click', function () {
                    const form = document.getElementById('template-editor-form');
                    form.action = button.dataset.action;
                    document.getElementById('template-modal-name').textContent = 'Edit Template: ' + button.dataset.name;
                    document.getElementById('modal-title').value = button.dataset.title;
                    document.getElementById('modal-subject').value = button.dataset.subject;
                    document.getElementById('modal-body').value = button.dataset.body;
                    modalAudience.value = button.dataset.audience;
                    modalTemplateRecipient.value = button.dataset.recipient || '';
                    updateTemplateRecipientVisibility();
                    templateModal.showModal();
                });
            });

            const triggerAudience = document.getElementById('trigger-audience');
            const haloBlock = document.getElementById('halopsa-block');
            const adminCreateTicket = document.getElementById('admin_create_halo_ticket');
            const haloFields = document.getElementById('halopsa-fields');

            const toggleHaloBlock = function () {
                if (triggerAudience.value === 'admin') {
                    haloBlock.style.display = 'block';
                } else {
                    haloBlock.style.display = 'none';
                    adminCreateTicket.checked = false;
                }
            };

            const toggleHaloFields = function () {
                const enabled = triggerAudience.value === 'admin' && adminCreateTicket.checked;
                haloFields.style.opacity = enabled ? '1' : '0.55';
                haloFields.querySelectorAll('input').forEach(function (input) {
                    input.required = enabled;
                });
            };

            triggerAudience.addEventListener('change', function () {
                toggleHaloBlock();
                toggleHaloFields();
            });

            adminCreateTicket.addEventListener('change', toggleHaloFields);
            toggleHaloBlock();
            toggleHaloFields();

            [variableModal, templateModal, triggerModal].forEach(function (dialog) {
                dialog.addEventListener('click', function (event) {
                    if (event.target === dialog) {
                        dialog.close();
                    }
                });
            });
        });
    </script>
@endsection
