import { useEffect, useState } from 'react'
import { Alert, Tab, Tabs } from '../components/ui'
import { useAuth } from '../context/AuthContext'
import { useProperty } from '../context/PropertyContext'
import { collections } from '../api/finance'
import { CollectionsReport } from '../components/finance/CollectionsReport'
import { Receivables } from '../components/finance/Receivables'
import { InvoicesPanel } from '../components/finance/InvoicesPanel'
import { FinanceAnalytics } from '../components/finance/FinanceAnalytics'

// Finance: the property's money — what was collected (Collections), what's
// owed (Receivables), the invoices themselves (Invoices — the same panel Front
// Desk embeds for checkout) and, for a Manager, Analytics. Front Desk Staff
// get the single-day collection report only; the backend enforces the same.
export default function Finance() {
  const { role } = useAuth()
  const { propertyId } = useProperty()
  const isAdmin = role === 'admin'
  const [tab, setTab] = useState('collections')
  // The outstanding balance, for the Receivables tab's title and card;
  // re-read after an invoice is settled so the figure follows.
  const [outstanding, setOutstanding] = useState(null)
  const [settledAt, setSettledAt] = useState(0)

  useEffect(() => {
    let active = true
    collections()
      .then((c) => { if (active) setOutstanding(c.outstanding) })
      .catch(() => { if (active) setOutstanding(null) })
    return () => { active = false }
  }, [settledAt])

  if (!propertyId) return <Alert variant="info">Select a property to view its finances.</Alert>

  return (
    <div>
      <h1 className="mb-4 text-2xl font-bold">Finance</h1>
      <Tabs activeKey={tab} onSelect={setTab} className="mb-4">
        <Tab eventKey="collections" title="Collections">
          <CollectionsReport allowMonthly={isAdmin} />
        </Tab>
        <Tab eventKey="receivables"
          title={outstanding?.count > 0 ? `Receivables (${outstanding.count})` : 'Receivables'}>
          <Receivables propertyId={propertyId} outstanding={outstanding}
            onOpenInvoices={() => setTab('invoices')} />
        </Tab>
        <Tab eventKey="invoices" title="Invoices">
          <InvoicesPanel propertyId={propertyId} onSettled={() => setSettledAt(Date.now())} />
        </Tab>
        {isAdmin && (
          <Tab eventKey="analytics" title="Analytics">
            <FinanceAnalytics />
          </Tab>
        )}
      </Tabs>
    </div>
  )
}
