import { Link } from 'react-router-dom'
import { Container, Card, Button } from 'react-bootstrap'

// Public page (no auth) — see the /terms route in App.jsx. Linked from the
// login screen and the app footer.
//
// NOTE: This is a practical starting template, not legal advice. Replace the
// [bracketed] placeholders and have it reviewed by counsel before you rely on it.
const LAST_UPDATED = 'June 22, 2026'

function Section({ title, children }) {
  return (
    <section className="mb-4">
      <h2 className="h5 fw-bold">{title}</h2>
      {children}
    </section>
  )
}

export default function TermsOfService() {
  return (
    <Container className="py-5" style={{ maxWidth: 820 }}>
      <div className="d-flex justify-content-between align-items-center mb-4">
        <Link to="/" className="sv-serif fw-bold fs-4 text-decoration-none">
          Stay<span className="sv-accent">Vanta</span>
        </Link>
        <Button as={Link} to="/" variant="outline-secondary" size="sm">Back</Button>
      </div>

      <Card className="shadow-sm">
        <Card.Body className="p-4 p-md-5">
          <h1 className="h3 fw-bold mb-1">Terms of Service</h1>
          <p className="text-muted small mb-4">Last updated: {LAST_UPDATED}</p>

          <p>
            These Terms of Service (&ldquo;Terms&rdquo;) govern access to and use of StayVanta, an
            all-in-one hotel &amp; resort management platform (the &ldquo;Service&rdquo;) operated by
            [Your registered business name] (&ldquo;StayVanta,&rdquo; &ldquo;we,&rdquo; or
            &ldquo;us&rdquo;). By accessing or using the Service, you agree to these Terms. If you do
            not agree, do not use the Service.
          </p>

          <Section title="The Service">
            <p className="mb-0">
              StayVanta is provided on a subscription basis to hotels and resorts (each, a
              &ldquo;Property&rdquo;). It includes tools for inventory, front desk and reservations,
              guest records, food &amp; orders, invoicing, staff management, and reporting. The
              platform operator manages subscriptions; each Property&rsquo;s administrators and
              receptionists use the operational features under their assigned roles.
            </p>
          </Section>

          <Section title="Accounts &amp; eligibility">
            <ul className="mb-0">
              <li>
                Accounts are created for staff with a defined role (owner, admin, or receptionist).
                You must provide accurate information and keep it current.
              </li>
              <li>
                You are responsible for safeguarding your credentials and for all activity under your
                account. Notify us promptly of any unauthorized use.
              </li>
              <li>
                Accounts are for the assigned individual; do not share credentials. Because the
                Service records the staff member responsible for each action, shared accounts
                undermine that accountability.
              </li>
            </ul>
          </Section>

          <Section title="Acceptable use">
            <p>You agree not to:</p>
            <ul className="mb-0">
              <li>use the Service unlawfully or in violation of these Terms;</li>
              <li>access data or accounts you are not authorized to access;</li>
              <li>
                attempt to disrupt, reverse engineer, probe, or circumvent the security or integrity
                of the Service;
              </li>
              <li>upload malicious code or misuse the platform&rsquo;s resources.</li>
            </ul>
          </Section>

          <Section title="Subscriptions &amp; fees">
            <p className="mb-0">
              Access to the Service for a Property requires an active subscription at the applicable
              monthly fee. Subscriptions remain in effect until cancelled or until they expire or are
              set inactive. We may suspend or restrict access where a subscription is inactive,
              expired, or unpaid. Unless stated otherwise in a separate agreement, fees are
              non-refundable except where required by law.
            </p>
          </Section>

          <Section title="Customer data &amp; responsibilities">
            <p className="mb-0">
              A Property and its staff are responsible for the guest and operational data they enter,
              including its accuracy and for having any necessary rights or consents to provide it.
              The Property is the controller of its guests&rsquo; personal data; StayVanta processes
              that data on the Property&rsquo;s behalf as described in our{' '}
              <Link to="/privacy">Privacy Policy</Link>, which is incorporated into these Terms.
            </p>
          </Section>

          <Section title="Intellectual property">
            <p className="mb-0">
              The Service, including its software, design, and content (excluding data you enter),
              is owned by StayVanta and its licensors and is protected by applicable laws. We grant
              you a limited, non-exclusive, non-transferable right to use the Service in accordance
              with these Terms; no other rights are granted.
            </p>
          </Section>

          <Section title="Service availability">
            <p className="mb-0">
              We aim to keep the Service available and reliable, but it is provided on an &ldquo;as
              is&rdquo; and &ldquo;as available&rdquo; basis. We may modify, suspend, or discontinue
              features, and perform maintenance, from time to time. To the maximum extent permitted by
              law, we disclaim warranties of any kind, whether express or implied.
            </p>
          </Section>

          <Section title="Limitation of liability">
            <p className="mb-0">
              To the maximum extent permitted by law, StayVanta will not be liable for any indirect,
              incidental, special, consequential, or punitive damages, or for any loss of data,
              revenue, or profits, arising from or related to your use of the Service. Our aggregate
              liability for any claim relating to the Service will not exceed the subscription fees
              paid for the Service in the [12] months preceding the claim.
            </p>
          </Section>

          <Section title="Suspension &amp; termination">
            <p className="mb-0">
              We may suspend or terminate access if these Terms are violated, if a subscription is
              inactive or unpaid, or as needed to protect the Service or its users. You may stop using
              the Service at any time. Provisions that by their nature should survive termination
              (including ownership, disclaimers, and limitations of liability) will survive.
            </p>
          </Section>

          <Section title="Changes to these Terms">
            <p className="mb-0">
              We may update these Terms from time to time. Material changes will be reflected by
              updating the &ldquo;Last updated&rdquo; date above and, where appropriate, by additional
              notice. Continued use of the Service after changes take effect constitutes acceptance.
            </p>
          </Section>

          <Section title="Governing law">
            <p className="mb-0">
              These Terms are governed by the laws of [your jurisdiction], without regard to its
              conflict-of-laws rules, and any disputes will be subject to the courts located in
              [your jurisdiction].
            </p>
          </Section>

          <Section title="Contact">
            <p className="mb-0">
              Questions about these Terms can be sent to{' '}
              <a href="mailto:[legal@your-domain.com]">[legal@your-domain.com]</a>, or by mail to
              [Your registered business name and address].
            </p>
          </Section>
        </Card.Body>
      </Card>

      <p className="text-center text-muted small mt-4 mb-0">
        &copy; {new Date().getFullYear()} StayVanta · <Link to="/privacy" className="text-decoration-none">Privacy Policy</Link>
      </p>
    </Container>
  )
}
