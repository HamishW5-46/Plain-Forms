import { h, Fragment, Button, Glyph } from './wp';
import { PageHeader, Card } from './common';
export default function Dashboard({ data, links }) {
    const { stats, defaults } = data;
    const metrics = [
        ['forms', 'Forms', 'feedback', links.forms],
        ['today', 'Enquiries today', 'calendar-alt', links.submissions],
        ['month', 'This month', 'chart-bar', links.submissions],
        [
            'unread',
            'Unread enquiries',
            'email-alt',
            links.submissions && `${links.submissions}&status=new`,
        ],
        [
            'spam',
            'Marked as spam',
            'shield',
            links.submissions && `${links.submissions}&status=spam`,
        ],
        [
            'failures',
            'Email failures',
            'warning',
            links.submissions && `${links.submissions}&delivery_failed=1`,
        ],
    ];
    return (
        <>
            <PageHeader
                title="A place for every enquiry"
                description="Create welcoming forms and keep track of the conversations that matter."
                actions={
                    <>
                        <Button variant="secondary" href={links.forms}>
                            Manage forms
                        </Button>
                        <Button variant="primary" href={links.add}>
                            <Glyph name="plus-alt2" /> Create a form
                        </Button>
                    </>
                }
            />
            <div className="plainforms-metric-grid">
                {metrics.map(([key, label, icon, href]) => {
                    const Tag = href ? 'a' : 'div';
                    return (
                        <Tag
                            key={key}
                            className={`plainforms-metric ${key === 'failures' && stats[key] > 0 ? 'needs-attention' : ''}`}
                            href={href || undefined}
                        >
                            <span className="plainforms-metric-icon">
                                <Glyph name={icon} />
                            </span>
                            <span className="plainforms-metric-value">
                                {Number(stats[key]).toLocaleString()}
                            </span>
                            <span className="plainforms-metric-label">{label}</span>
                        </Tag>
                    );
                })}
            </div>
            <div className="plainforms-dashboard-columns">
                <Card
                    title="From a first hello to a conversation"
                    description="A few simple steps to put your form to work."
                >
                    <ol className="plainforms-onboarding">
                        <li>
                            <span>1</span>
                            <div>
                                <h3>Create your form</h3>
                                <p>
                                    Choose the fields you need and make the
                                    wording your own.
                                </p>
                                <Button variant="link" href={links.add}>
                                    Open the form builder →
                                </Button>
                            </div>
                        </li>
                        <li>
                            <span>2</span>
                            <div>
                                <h3>Set up delivery and protection</h3>
                                <p>
                                    Check recipients, configure Turnstile and
                                    choose a retention period.
                                </p>
                                {links.settings && (
                                    <Button
                                        variant="link"
                                        href={links.settings}
                                    >
                                        Review settings →
                                    </Button>
                                )}
                            </div>
                        </li>
                        <li>
                            <span>3</span>
                            <div>
                                <h3>Add it to a page</h3>
                                <p>
                                    Insert the Plain Forms block in the
                                    WordPress editor, select your form and test
                                    it before publishing.
                                </p>
                            </div>
                        </li>
                    </ol>
                </Card>
                <div className="plainforms-dashboard-aside">
                    <Card title="Your defaults">
                        <ul className="plainforms-readiness">
                            <li>
                                <Glyph name="database" />
                                <span>Submission storage</span>
                                <strong
                                    className={
                                        defaults.storage ? 'plainforms-positive' : ''
                                    }
                                >
                                    {defaults.storage ? 'Enabled' : 'Disabled'}
                                </strong>
                            </li>
                            <li>
                                <Glyph name="shield" />
                                <span>Turnstile</span>
                                <strong>
                                    {defaults.turnstile
                                        ? defaults.verificationReady
                                            ? 'Ready'
                                            : 'Needs keys'
                                        : 'Off'}
                                </strong>
                            </li>
                        </ul>
                        {links.settings && (
                            <Button variant="secondary" href={links.settings}>
                                Adjust settings
                            </Button>
                        )}
                    </Card>
                    {stats.failures > 0 && (
                        <Card
                            title="Some notifications need attention"
                            className="plainforms-attention-card"
                        >
                            <p>
                                Your stored enquiries are safe. Open failed
                                notifications to review them and resend.
                            </p>
                            {links.submissions && (
                                <Button
                                    variant="secondary"
                                    href={`${links.submissions}&delivery_failed=1`}
                                >
                                    Review email failures
                                </Button>
                            )}
                        </Card>
                    )}
                    <div className="plainforms-dashboard-tip">
                        <Glyph name="lightbulb" />
                        <p>
                            <strong>A small form goes a long way.</strong>
                            <br />
                            Only ask for what you need. Clear labels and fewer
                            required fields make getting in touch easier.
                        </p>
                    </div>
                </div>
            </div>
        </>
    );
}
