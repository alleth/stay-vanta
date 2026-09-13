// Minimal line-icon set for the post-login Hub (src/pages/Hub.jsx) and the
// header's Home affordance. Deliberately hand-rolled rather than an npm icon
// library (see frontend-design skill: no new UI dependencies) — plain
// primitives (rect/circle/line) kept simple on purpose so they read clearly
// at small sizes. All 24x24 viewBox, stroke-based, sized via className.
const BASE = {
  viewBox: '0 0 24 24',
  fill: 'none',
  stroke: 'currentColor',
  strokeWidth: 1.6,
  strokeLinecap: 'round',
  strokeLinejoin: 'round',
}

export function HomeIcon({ className }) {
  return (
    <svg {...BASE} className={className}>
      <path d="M3 11l9-7 9 7" />
      <rect x="5" y="11" width="14" height="9" rx="1" />
      <rect x="10" y="14" width="4" height="6" />
    </svg>
  )
}

export function DashboardIcon({ className }) {
  return (
    <svg {...BASE} className={className}>
      <rect x="5" y="13" width="3.5" height="7" rx="0.5" />
      <rect x="10.25" y="9" width="3.5" height="11" rx="0.5" />
      <rect x="15.5" y="5" width="3.5" height="15" rx="0.5" />
    </svg>
  )
}

export function BuildingIcon({ className }) {
  return (
    <svg {...BASE} className={className}>
      <rect x="4" y="9" width="7" height="11" rx="1" />
      <rect x="13" y="4" width="7" height="16" rx="1" />
    </svg>
  )
}

export function BoxIcon({ className }) {
  return (
    <svg {...BASE} className={className}>
      <path d="M3 8l9-5 9 5-9 5-9-5Z" />
      <path d="M3 8v8l9 5 9-5V8" />
      <path d="M12 13v8" />
    </svg>
  )
}

export function DoorIcon({ className }) {
  return (
    <svg {...BASE} className={className}>
      <rect x="5" y="3" width="14" height="18" rx="1" />
      <circle cx="15" cy="12" r="1" />
    </svg>
  )
}

export function UserIcon({ className }) {
  return (
    <svg {...BASE} className={className}>
      <circle cx="12" cy="8" r="3.5" />
      <path d="M5 20c0-4 3.5-7 7-7s7 3 7 7" />
    </svg>
  )
}

export function ReceiptIcon({ className }) {
  return (
    <svg {...BASE} className={className}>
      <rect x="5" y="4" width="14" height="16" rx="2" />
      <line x1="8" y1="9" x2="16" y2="9" />
      <line x1="8" y1="13" x2="16" y2="13" />
      <line x1="8" y1="17" x2="12" y2="17" />
    </svg>
  )
}

// The header's theme toggle shows the theme you'd switch *to*: sun while
// dark, moon while light.
export function SunIcon({ className }) {
  return (
    <svg {...BASE} className={className}>
      <circle cx="12" cy="12" r="4" />
      <path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4" />
    </svg>
  )
}

export function MoonIcon({ className }) {
  return (
    <svg {...BASE} className={className}>
      <path d="M20 14.5A8 8 0 0 1 9.5 4a8 8 0 1 0 10.5 10.5Z" />
    </svg>
  )
}

export function UsersIcon({ className }) {
  return (
    <svg {...BASE} className={className}>
      <circle cx="9" cy="8" r="3" />
      <path d="M3.5 20c0-3.5 2.5-6.5 5.5-6.5s5.5 3 5.5 6.5" />
      <circle cx="17" cy="7" r="2.5" />
      <path d="M13 15.5c1-2 2.5-3 4-3s3.5 2 4 5.5" />
    </svg>
  )
}
