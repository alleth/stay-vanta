import { useEffect, useState } from 'react'
import { Alert } from '../ui'
import { financeSummary } from '../../api/finance'
import { SkeletonCards } from '../Skeleton'
import { StatTiles } from '../StatCard'
import { SeasonalityChart } from './SeasonalityChart'
import { describeError } from '../../utils/apiError'

function SectionTitle({ children }) {
  return <h2 className="mb-3 text-xs font-semibold uppercase tracking-[0.04em] text-muted">{children}</h2>
}

// Finance → Analytics (Manager only): collected by period, then seasonality.
export function FinanceAnalytics() {
  const [data, setData] = useState(null)
  const [error, setError] = useState(null)

  useEffect(() => {
    financeSummary().then(setData).catch((err) => setError(describeError(err)))
  }, [])

  return (
    <>
      <SectionTitle>Collected</SectionTitle>
      {error && <Alert variant="danger">{error}</Alert>}
      {!error && !data && <SkeletonCards count={4} />}
      {data && (
        <StatTiles
          money
          tiles={[
            { label: 'This week', value: data.collected.week },
            { label: 'This month', value: data.collected.month },
            { label: 'Year to date', value: data.collected.ytd },
            { label: 'All time', value: data.collected.all_time },
          ]}
        />
      )}
      <SectionTitle>Seasonality</SectionTitle>
      <SeasonalityChart />
    </>
  )
}
