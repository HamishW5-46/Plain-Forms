import {
    h,
    Fragment,
    TextControl,
    TextareaControl,
    ToggleControl,
    SelectControl,
    Notice,
} from './wp';
import { inheritOptions, inherited, fromInherited } from './state';
import { Card, EmailsControl } from './common';
import EmailTemplateEditor from './EmailTemplateEditor';

export default function FormSettings({ form, onChange, defaults, templates }) {
    const c = form.config,
        emails = c.fields.filter((f) => f.type === 'email'),
        storage = c.storage.enabled ?? defaults.storage;
    const set = (group, key, value) =>
        onChange(
            { ...c, [group]: { ...c[group], [key]: value } },
            `${group}-${key}`,
        );
    const mail = (group, patch) =>
        onChange(
            { ...c, [group]: { ...c[group], ...patch } },
            `${group}-template`,
        );
    return (
        <div className="plainforms-settings-grid">
            <Card
                title="Form details"
                description="Give visitors context before they send an enquiry."
            >
                <TextareaControl
                    label="Description"
                    value={c.description}
                    onChange={(v) =>
                        onChange({ ...c, description: v }, 'description')
                    }
                    __nextHasNoMarginBottom
                />
                <SelectControl
                    label="Store submissions"
                    value={inherited(c.storage.enabled)}
                    options={inheritOptions}
                    onChange={(v) =>
                        set('storage', 'enabled', fromInherited(v))
                    }
                    help={`Global default: ${defaults.storage ? 'enabled' : 'disabled'}. Stored enquiries remain available if email delivery fails.`}
                    __nextHasNoMarginBottom
                    __next40pxDefaultSize
                />
                {!storage && (
                    <Notice status="warning" isDismissible={false}>
                        With storage disabled, email is your only copy of each
                        enquiry.
                    </Notice>
                )}
            </Card>
            <Card
                title="Confirmation"
                description="What visitors see after a successful submission."
            >
                <TextareaControl
                    label="Success message"
                    value={c.confirmation.message}
                    onChange={(v) => set('confirmation', 'message', v)}
                    placeholder={defaults.successMessage}
                    help="Leave blank to use the global success message."
                    rows={4}
                    __nextHasNoMarginBottom
                />
            </Card>
            <Card
                title="Email notifications"
                description="Choose who receives this form’s enquiries."
                className="plainforms-mail-card"
            >
                <ToggleControl
                    label="Send an email notification"
                    checked={c.notification.enabled}
                    onChange={(v) => set('notification', 'enabled', v)}
                    __nextHasNoMarginBottom
                />
                <div className="plainforms-two-columns">
                    <EmailsControl
                        label="Recipients"
                        value={c.notification.recipients}
                        onChange={(v) => set('notification', 'recipients', v)}
                        help="Separate addresses with commas. Leave blank to use the global recipients."
                        disabled={!c.notification.enabled}
                    />
                    <SelectControl
                        label="Reply-To field"
                        value={c.notification.reply_to_field}
                        options={[
                            { value: '', label: 'No Reply-To' },
                            ...emails.map((f) => ({
                                value: f.id,
                                label: `${f.label} (${f.id})`,
                            })),
                        ]}
                        onChange={(v) =>
                            set('notification', 'reply_to_field', v)
                        }
                        help="Replies go to the validated email address entered in this field."
                        disabled={!c.notification.enabled}
                        __nextHasNoMarginBottom
                        __next40pxDefaultSize
                    />
                </div>
                <TextControl
                    label="Email subject"
                    value={c.notification.subject}
                    onChange={(v) => set('notification', 'subject', v)}
                    help="Leave blank for the global subject. Use {form_title}, {submission_id} or a field ID in braces."
                    disabled={!c.notification.enabled}
                    __nextHasNoMarginBottom
                    __next40pxDefaultSize
                />
                {c.notification.enabled && (
                    <EmailTemplateEditor
                        kind="notification"
                        settings={c.notification}
                        form={form}
                        templates={templates}
                        onChange={(patch) => mail('notification', patch)}
                    />
                )}
                {!storage && !c.notification.enabled && (
                    <Notice status="error" isDismissible={false}>
                        Enable storage or notifications so this form can receive
                        enquiries. A submitter receipt is an additional
                        acknowledgement, not your copy.
                    </Notice>
                )}
            </Card>
            <Card
                title="Submitter receipt"
                description="Send an additional acknowledgement to the person who filled out this form."
                className="plainforms-mail-card"
            >
                <ToggleControl
                    label="Send a receipt to the submitter"
                    checked={c.receipt.enabled}
                    onChange={(enabled) =>
                        mail('receipt', {
                            enabled,
                            ...(enabled && !c.receipt.email_field
                                ? {
                                      email_field:
                                          c.notification.reply_to_field ||
                                          emails[0]?.id ||
                                          '',
                                  }
                                : {}),
                        })
                    }
                    __nextHasNoMarginBottom
                />
                {c.receipt.enabled && (
                    <>
                        {!emails.length && (
                            <Notice status="warning" isDismissible={false}>
                                Add an email field to this form before enabling
                                receipts.
                            </Notice>
                        )}
                        <div className="plainforms-two-columns">
                            <SelectControl
                                label="Receipt recipient field"
                                value={c.receipt.email_field}
                                options={[
                                    {
                                        value: '',
                                        label: 'Select an email field',
                                    },
                                    ...emails.map((f) => ({
                                        value: f.id,
                                        label: `${f.label} (${f.id})`,
                                    })),
                                ]}
                                onChange={(v) =>
                                    set('receipt', 'email_field', v)
                                }
                                help="Receipts go only to this validated email value. If an optional field is blank, the receipt is skipped."
                                __nextHasNoMarginBottom
                                __next40pxDefaultSize
                            />
                            <TextControl
                                label="Receipt Reply-To address"
                                type="email"
                                value={c.receipt.reply_to}
                                onChange={(v) => set('receipt', 'reply_to', v)}
                                help="Optional team address for replies. Blank uses your normal sender."
                                __nextHasNoMarginBottom
                                __next40pxDefaultSize
                            />
                        </div>
                        <TextControl
                            label="Receipt subject"
                            value={c.receipt.subject}
                            onChange={(v) => set('receipt', 'subject', v)}
                            help="Supports {form_title}, {submission_id}, {system.site_name} and your field IDs."
                            __nextHasNoMarginBottom
                            __next40pxDefaultSize
                        />
                        <EmailTemplateEditor
                            kind="receipt"
                            settings={c.receipt}
                            form={form}
                            templates={templates}
                            onChange={(patch) => mail('receipt', patch)}
                        />
                        <p className="plainforms-muted">
                            Delivery is tracked separately from your
                            notification. A failed receipt does not reject an
                            otherwise accepted enquiry.
                        </p>
                    </>
                )}
            </Card>
            <Card
                title="Spam verification"
                description="Use the global protection or choose a setting for this form."
                className="plainforms-mail-card"
            >
                <SelectControl
                    label="Cloudflare Turnstile"
                    value={inherited(c.turnstile)}
                    options={inheritOptions}
                    onChange={(v) =>
                        onChange(
                            { ...c, turnstile: fromInherited(v) },
                            'turnstile',
                        )
                    }
                    help={`Global default: ${defaults.turnstile ? 'enabled' : 'disabled'}.`}
                    __nextHasNoMarginBottom
                    __next40pxDefaultSize
                />
                {(c.turnstile ?? defaults.turnstile) &&
                    !defaults.verificationReady && (
                        <Notice status="warning" isDismissible={false}>
                            Turnstile keys are incomplete. Add both keys in
                            Settings before publishing.
                        </Notice>
                    )}
                <p className="plainforms-muted">
                    Honeypot, timing protection and rate limits follow your
                    global settings.
                </p>
            </Card>
        </div>
    );
}
