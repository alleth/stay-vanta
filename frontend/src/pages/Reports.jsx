import { useEffect, useState } from 'react'
import { Row, Col, Card, Table, Badge, Button, Spinner, Alert } from 'react-bootstrap'
import { useAuth } from '../context/AuthContext'
import { getOverview, updateProperty } from '../api/reports'
import { guestStats } from '../api/guests'

// A row of count cards, used by both the owner and admin reports.
function CountCards({ tiles, cols = 4 }) {
  return (
    <Row className="g-3 mb-4">
      {tiles.map((t) => (
        <Col key={t.label} sm={6} lg={cols}>
          <Card className="h-100">
            <Card.Body className="p-4">
              <div
                className="small text-uppercase"
                style={{ color: 'var(--sv-muted)', letterSpacing: '0.04em' }}
              >
                {t.label}
              </div>
              <div className="sv-serif fw-bold mt-2" style={{ fontSize: '2.25rem' }}>
                {t.value}
              </div>
            </Card.Body>
          </Card>
        </Col>
      ))}
    </Row>
  )
}

function Loading() {
  return (
    <div className="text-center py-5" style={{ color: 'var(--sv-muted)' }}>
      <Spinner size="sm" className="me-2" /> Loading reports…
    </div>
  )
}

// Admin (hotel/resort head): guest counts for their property.
function GuestReport() {
  const [stats, setStats] = useState(null)
  const [error, setError] = useState(null)

  useEffect(() => {
    guestStats().then(setStats).catch(() => setError('Could not load the guest report.'))
  }, [])

  if (error) return <Alert variant="danger">{error}</Alert>
  if (!stats) return <Loading />

  const tiles = [
    { label: 'Total guests', value: stats.total },
    { label: 'In-house', value: stats.inHouse },
    { label: 'Local', value: stats.local },
    { label: 'Foreign', value: stats.foreign },
  ]

  return (
    <div>
      <h1 className="sv-serif fw-bold mb-1" style={{ fontSize: '2rem' }}>Reports</h1>
      <p className="mb-4" style={{ color: 'var(--sv-muted)' }}>
        Guest counts for your property — total, currently in-house, and local vs foreign.
      </p>
      <CountCards tiles={tiles} cols={3} />
    </div>
  )
}

// Owner (platform operator): registered hotels/resorts + subscription status.
function SubscriptionReport() {
  const [overview, setOverview] = useState(null)
  const [error, setError] = useState(null)
  const [busyId, setBusyId] = useState(null)

  async function load() {
    setError(null)
    try {
      setOverview(await getOverview())
    } catch {
      setError('Could not load reports.')
    }
  }

  useEffect(() => {
    load()
  }, [])

  async function toggle(property) {
    const next = property.subscription_status === 'active' ? 'inactive' : 'active'
    setBusyId(property.id)
    try {
      await updateProperty(property.id, { subscription_status: next })
      await load()
    } catch {
      setError('Could not update the subscription.')
    } finally {
      setBusyId(null)
    }
  }

  if (error) return <Alert variant="danger">{error}</Alert>
  if (!overview) return <Loading />

  const tiles = [
    { label: 'Hotels & Resorts', value: overview.total_properties },
    { label: 'Active subscriptions', value: overview.active_subscriptions },
    { label: 'Inactive subscriptions', value: overview.inactive_subscriptions },
  ]

  return (
    <div>
      <h1 className="sv-serif fw-bold mb-1" style={{ fontSize: '2rem' }}>Reports</h1>
      <p className="mb-4" style={{ color: 'var(--sv-muted)' }}>
        Registered hotels &amp; resorts and the status of their subscriptions.
      </p>

      <CountCards tiles={tiles} cols={4} />

      <Card>
        <Card.Header>Registered hotels &amp; resorts</Card.Header>
        <Table responsive hover className="align-middle mb-0">
          <thead>
            <tr>
              <th>Name</th>
              <th>Type</th>
              <th>Subscription</th>
              <th>Expires</th>
              <th className="text-end">Action</th>
            </tr>
          </thead>
          <tbody>
            {overview.properties.length === 0 && (
              <tr>
                <td colSpan={5} className="text-center py-4" style={{ color: 'var(--sv-muted)' }}>
                  No hotels or resorts registered yet.
                </td>
              </tr>
            )}
            {overview.properties.map((p) => (
              <tr key={p.id}>
                <td className="fw-medium">{p.name}</td>
                <td className="text-capitalize">{p.type}</td>
                <td>
                  {p.subscription_active ? (
                    <Badge bg="warning">Active</Badge>
                  ) : (
                    <Badge bg="secondary">Inactive</Badge>
                  )}
                </td>
                <td style={{ color: 'var(--sv-muted)' }}>
                  {p.subscription_expires_at ?? '—'}
                </td>
                <td className="text-end">
                  <Button
                    size="sm"
                    variant="outline-secondary"
                    disabled={busyId === p.id}
                    onClick={() => toggle(p)}
                  >
                    {busyId === p.id ? (
                      <Spinner size="sm" />
                    ) : p.subscription_status === 'active' ? (
                      'Deactivate'
                    ) : (
                      'Activate'
                    )}
                  </Button>
                </td>
              </tr>
            ))}
          </tbody>
        </Table>
      </Card>
    </div>
  )
}

export default function Reports() {
  const { role } = useAuth()
  return role === 'owner' ? <SubscriptionReport /> : <GuestReport />
}
