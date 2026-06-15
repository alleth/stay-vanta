import { Card, ListGroup } from 'react-bootstrap'

// Placeholder for a module that is scaffolded but not yet implemented.
// `features` documents the planned sub-features for that module.
export default function ModuleStub({ title, subtitle, features = [] }) {
  return (
    <div className="mx-auto" style={{ maxWidth: 880 }}>
      <h1 className="h3 fw-bold mb-1">{title}</h1>
      {subtitle && <p className="text-muted">{subtitle}</p>}
      <Card className="shadow-sm">
        <Card.Header className="fw-semibold">Planned features</Card.Header>
        <ListGroup variant="flush">
          {features.map((f) => (
            <ListGroup.Item key={f}>{f}</ListGroup.Item>
          ))}
        </ListGroup>
      </Card>
    </div>
  )
}
