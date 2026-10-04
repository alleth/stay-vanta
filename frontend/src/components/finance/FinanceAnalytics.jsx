import { useEffect, useState } from 'react'
import { Alert } from '../ui'
import { financeSummary } from '../../api/finance'
import { SkeletonCards } from '../Skeleton'
import { StatTiles } from '../StatCard'
import { SeasonalityChart } from './SeasonalityChart'
import { describeError } from '../../utils/apiError'
import { formatMoney } from '../../utils/format'

const PERIOD_LABELS = { week: 'This week', month: 'This month', ytd: 'Year to date', all_time: 'All time' }

function SectionTitle({ children }) {
  return <h2 className="mb-3 text-xs font-semibold uppercase tracking-[0.04em] text-muted">{children}</h2>
}

// Finance → Analytics (Manager only): Net Collected by period (cash in less
// refunds, build step 7c), then seasonality.
export function FinanceAnalytics() {
  const [data, setData] = useState(null)
  const [error, setError] = useState(null)

  useEffect(() => {
    financeSummary().then(setData).catch((err) => setError(describeError(err)))
  }, [])

  return (
    <>
      <SectionTitle>Net collected</SectionTitle>
      {error && <Alert variant="danger">{error}</Alert>}
      {!error && !data && <SkeletonCards count={4} />}
      {data && (
        <StatTiles
          money
          className={refundNotes(data).length ? 'mb-2' : 'mb-8'}
          tiles={Object.entries(PERIOD_LABELS).map(([key, label]) => ({ label, value: data.collected[key] }))}
        />
      )}
      {data && refundNotes(data).length > 0 && (
        <p className="mb-8 text-xs text-muted">{refundNotes(data).join(' · ')}</p>
      )}
      <SectionTitle>Seasonality</SectionTitle>
      <SeasonalityChart />
    </>
  )
}

// "This month: ₱12,000.00 collected, ₱900.00 refunded" for each period with
// refunds in it (absent from a server before step 7c-2).
function refundNotes(data) {
  return Object.entries(PERIOD_LABELS)
    .filter(([key]) => data.cash?.[key]?.refunded > 0)
    .map(([key, label]) => `${label}: ${formatMoney(data.cash[key].collected)} collected, `
      + `${formatMoney(data.cash[key].refunded)} refunded`)
}
