import { Link } from 'react-router-dom'
import { Container, Card, Button } from 'react-bootstrap'

// Public page (no auth) — see the /privacy route in App.jsx. Linked from the
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

export default function PrivacyPolicy() {
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
          <h1 className="h3 fw-bold mb-1">Privacy Policy</h1>
          <p className="text-muted small mb-4">Last updated: {LAST_UPDATED}</p>

          <p>
            StayVanta is an all-in-one hotel &amp; resort management platform. This Privacy Policy
            explains what information the platform handles, how it is used, and the choices
            available to you. It applies to the StayVanta web application and its API.
          </p>
          <p>
            StayVanta is offered on a subscription basis to hotels and resorts (each, a
            &ldquo;Property&rdquo;). For information that a Property&rsquo;s staff record about their
            guests, the <strong>Property is the data controller</strong> and StayVanta acts as a
            <strong> data processor</strong> on the Property&rsquo;s behalf. Guests with questions
            about their information should contact the Property they stayed with.
          </p>

          <Section title="Information we handle">
            <ul>
              <li>
                <strong>Guest information</strong> entered by Property staff: name, nationality,
                contact number, email address, postal address, and stay details (reservations,
                room assignments, food orders, and invoices).
              </li>
              <li>
                <strong>Staff account information</strong>: name, email, assigned role
                (owner / admin / receptionist), the Property they belong to, and a securely hashed
                password. We never store passwords in plain text.
              </li>
              <li>
                <strong>Operational and accountability records</strong>: reservations, stock
                movements, food orders, and invoices, each stamped with the staff member who
                performed the action and when. These audit records are core to the service and are
                retained even after related items are removed.
              </li>
              <li>
                <strong>Technical data</strong>: a session token (stored in your browser&rsquo;s
                local storage to keep you signed in) and standard server logs such as request
                metadata used for security and troubleshooting.
              </li>
            </ul>
            <p className="mb-0">
              StayVanta does not knowingly collect payment card numbers; billing is recorded as
              invoice totals and payment status only.
            </p>
          </Section>

          <Section title="How information is used">
            <ul className="mb-0">
              <li>To provide and operate the platform&rsquo;s features for the Property.</li>
              <li>To manage reservations, guest records, inventory, food orders, and invoicing.</li>
              <li>
                To maintain accountability &mdash; recording which staff member performed an action,
                which is the central purpose of the platform.
              </li>
              <li>To authenticate users, secure accounts, and prevent and investigate misuse.</li>
              <li>To operate, maintain, and improve the service.</li>
            </ul>
          </Section>

          <Section title="How information is shared">
            <p>We do not sell personal information. Information may be shared with:</p>
            <ul className="mb-0">
              <li>
                <strong>Authorized staff of the relevant Property</strong>, according to their role
                and the Property they are assigned to.
              </li>
              <li>
                <strong>Service providers</strong> that host and run the platform (for example, our
                cloud hosting and database providers), acting under appropriate confidentiality and
                data-protection obligations.
              </li>
              <li>
                <strong>Authorities or third parties</strong> where required by law, or to protect
                the rights, safety, and security of users and the service.
              </li>
            </ul>
          </Section>

          <Section title="Data retention">
            <p className="mb-0">
              Information is retained for as long as needed to provide the service and to meet legal,
              accounting, and accountability requirements. Some records are
              <strong> soft-deleted</strong> &mdash; hidden from everyday views but kept in the
              underlying ledger &mdash; so historical orders and the &ldquo;who did what&rdquo; audit
              trail remain accurate. When retention is no longer required, information is deleted or
              anonymized.
            </p>
          </Section>

          <Section title="Security">
            <p className="mb-0">
              Access is restricted by authenticated, role-based permissions; passwords are hashed;
              and the API is accessed over an authenticated, token-based channel. No method of
              transmission or storage is perfectly secure, so we cannot guarantee absolute security,
              but we work to protect information using reasonable safeguards.
            </p>
          </Section>

          <Section title="Cookies &amp; local storage">
            <p className="mb-0">
              StayVanta uses your browser&rsquo;s local storage to hold a session token that keeps
              you signed in. It is not used for advertising. Signing out or clearing your browser
              storage removes it.
            </p>
          </Section>

          <Section title="Your choices and rights">
            <p className="mb-0">
              Depending on your location, you may have rights to access, correct, or delete personal
              information, or to object to or restrict certain processing. Because Properties control
              their guests&rsquo; data, guests should direct such requests to the Property. Staff and
              Properties may contact us using the details below; we will assist the responsible
              Property in fulfilling valid requests.
            </p>
          </Section>

          <Section title="International processing">
            <p className="mb-0">
              The platform is hosted by cloud providers and may process and store information in data
              centers located outside your country. We take steps intended to ensure such processing
              remains protected in line with this policy.
            </p>
          </Section>

          <Section title="Changes to this policy">
            <p className="mb-0">
              We may update this Privacy Policy from time to time. Material changes will be reflected
              by updating the &ldquo;Last updated&rdquo; date above, and where appropriate, through
              additional notice.
            </p>
          </Section>

          <Section title="Contact">
            <p className="mb-0">
              Questions about this policy or your information can be sent to{' '}
              <a href="mailto:[privacy@your-domain.com]">[privacy@your-domain.com]</a>, or by mail to
              [Your registered business name and address].
            </p>
          </Section>
        </Card.Body>
      </Card>

      <p className="text-center text-muted small mt-4 mb-0">
        &copy; {new Date().getFullYear()} StayVanta. All rights reserved.
      </p>
    </Container>
  )
}
