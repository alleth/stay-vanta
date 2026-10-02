import { useEffect, useState } from 'react'
import { Alert } from '../components/ui'
import { useAuth } from '../context/AuthContext'
import { platformDashboard } from '../api/platform'
import { SkeletonCards, SkeletonTable } from '../components/Skeleton'
import { StatTiles } from '../components/StatCard'
import { SectionTitle } from '../components/operations/Operations'
import { apiErrorMessage } from '../utils/apiError'

// The Platform Owner's Dashboard: subscription revenue and subscribers. Hotel
// staff never see this page; their overview is Operations (pages/Operations.jsx).
export default function PlatformDashboard() {
  const { user } = useAuth()
  const [data, setData] = useState(null)
  const [error, setError] = useState(null)

  useEffect(() => {
    platformDashboard().then(setData).catch((err) => setError(apiErrorMessage(err)))
  }, [])

  if (error) return <Alert variant="danger">{error}</Alert>
  if (!data) {
    return (
      <>
        <SkeletonCards count={4} />
        <SkeletonTable rows={4} />
      </>
    )
  }

  return (
    <div>
      <h1 className="sv-serif mb-1 text-[2rem] font-bold">Dashboard</h1>
      <p className="mb-6 text-muted">
        Welcome back, {user?.name}. Platform revenue and active subscribers.
      </p>

      <SectionTitle>Subscription revenue</SectionTitle>
      <StatTiles
        money
        tiles={[
          { label: 'This week', value: data.revenue.week },
          { label: 'This month', value: data.revenue.month },
          { label: 'Year to date', value: data.revenue.ytd },
        ]}
      />

      <SectionTitle>Active users</SectionTitle>
      <StatTiles
        tiles={[
          { label: 'Hotels & Resorts', value: data.counts.hotels },
          { label: 'Active subscriptions', value: data.counts.active_subscriptions },
          { label: 'Registered Managers', value: data.counts.admins },
        ]}
      />
    </div>
  )
}
