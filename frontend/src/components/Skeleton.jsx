// Tailwind-based skeleton placeholders, used in place of spinners for initial
// data loads. (Inline action buttons keep their small <Spinner/>.)

export function Skeleton({ className = '' }) {
  return <div aria-hidden="true" className={`animate-pulse rounded bg-slate-200 ${className}`} />
}

// A stack of row-shaped bars — drop-in for a loading table/list inside a card.
export function SkeletonTable({ rows = 5 }) {
  return (
    <div className="space-y-3 p-3" aria-busy="true">
      <Skeleton className="h-6 w-1/3" />
      {Array.from({ length: rows }).map((_, i) => (
        <Skeleton key={i} className="h-9 w-full" />
      ))}
    </div>
  )
}

// Loading rows that sit inside an existing <tbody> (matches the column count).
export function SkeletonTableRows({ rows = 4, cols = 4 }) {
  return (
    <>
      {Array.from({ length: rows }).map((_, r) => (
        <tr key={r}>
          {Array.from({ length: cols }).map((_, c) => (
            <td key={c}><Skeleton className="h-5 w-full" /></td>
          ))}
        </tr>
      ))}
    </>
  )
}

// A responsive grid of card-shaped blocks (summary/stat cards, dashboards).
export function SkeletonCards({ count = 4 }) {
  return (
    <div className="mb-3 grid grid-cols-2 gap-3 lg:grid-cols-4" aria-busy="true">
      {Array.from({ length: count }).map((_, i) => (
        <Skeleton key={i} className="h-24 rounded-xl" />
      ))}
    </div>
  )
}
