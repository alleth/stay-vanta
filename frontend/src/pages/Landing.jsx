import { Link } from 'react-router-dom'
import BrandMark from '../components/BrandMark'

// The public landing page, adapted from the SocialPromo design reference.
//
// That reference is a 1080×1080 promo square, so what carries over is its
// identity — ink ground, amber mark and rules, Manrope, the two soft radial
// glows, the headline/eyebrow treatment — rather than its layout.
//
// Deliberately single-theme: it commits to the dark promo look instead of
// following the app's light/dark tokens, so every colour here is written out
// rather than taken from a token. That's also why it doesn't use the ui kit —
// this page shares the brand, not the admin interface's surfaces.
const INK = '#111827'
const AMBER = '#e9a23c'
const SUBHEAD = '#c7ccd6'

// Named in the reference's own subhead — the four things the platform covers.
const COVERS = ['Owner insights', 'Front desk', 'Inventory', 'Staff']

export default function Landing() {
  return (
    <div
      className="relative flex min-h-screen flex-col overflow-hidden"
      style={{ background: INK, fontFamily: "'Manrope', system-ui, -apple-system, 'Segoe UI', sans-serif" }}
    >
      {/* The reference's two glows, built up as light rather than flat fills:
          each is a broad halo, a hot near-white core, and a faint horizontal
          smear, drifting on three different periods. See .sv-flare in
          index.css for why they blend and blur the way they do. */}
      <div aria-hidden="true" className="pointer-events-none absolute inset-0 overflow-hidden">
        {/* Top-right — the brighter source. */}
        <div
          className="sv-flare sv-flare-halo -right-40 -top-40 h-[680px] w-[680px] rounded-full"
          style={{
            background:
              'radial-gradient(circle, rgba(233,162,60,0.20) 0%, rgba(233,162,60,0.09) 32%,'
              + ' rgba(233,162,60,0.03) 55%, transparent 72%)',
          }}
        />
        <div
          className="sv-flare sv-flare-core right-0 -top-16 h-[240px] w-[240px] rounded-full"
          style={{
            background:
              'radial-gradient(circle, rgba(255,236,196,0.34) 0%, rgba(233,162,60,0.20) 38%, transparent 70%)',
          }}
        />
        <div
          className="sv-flare sv-flare-streak -right-24 top-24 h-[3px] w-[560px] rounded-full"
          style={{
            background:
              'linear-gradient(90deg, transparent, rgba(233,162,60,0.35) 35%,'
              + ' rgba(255,236,196,0.55) 50%, rgba(233,162,60,0.35) 65%, transparent)',
          }}
        />

        {/* Bottom-left — dimmer and cooler, so the two don't read as twins. */}
        <div
          className="sv-flare sv-flare-halo -bottom-56 -left-56 h-[700px] w-[700px] rounded-full"
          style={{
            animationDelay: '-11s',
            background:
              'radial-gradient(circle, rgba(233,162,60,0.13) 0%, rgba(233,162,60,0.06) 35%, transparent 70%)',
          }}
        />
        <div
          className="sv-flare sv-flare-core -bottom-10 left-10 h-[200px] w-[200px] rounded-full"
          style={{
            animationDelay: '-7s',
            background:
              'radial-gradient(circle, rgba(255,236,196,0.20) 0%, rgba(233,162,60,0.12) 40%, transparent 72%)',
          }}
        />
      </div>

      <header className="relative z-10 flex items-center justify-between px-6 py-5 lg:px-12">
        <div className="flex items-center gap-3">
          <BrandMark className="h-8 w-8 shrink-0" style={{ '--color-body': AMBER, '--color-accent': AMBER }} />
          <span
            className="text-xl font-extrabold tracking-[-0.02em] sm:text-2xl"
            style={{ color: AMBER }}
          >
            StayVanta
          </span>
        </div>
        <Link
          to="/login"
          className="rounded-lg border px-4 py-2 text-sm font-semibold no-underline transition-colors"
          style={{ borderColor: 'rgba(233,162,60,0.45)', color: AMBER }}
          onMouseEnter={(e) => { e.currentTarget.style.background = 'rgba(233,162,60,0.12)' }}
          onMouseLeave={(e) => { e.currentTarget.style.background = 'transparent' }}
        >
          Sign in
        </Link>
      </header>

      <main className="relative z-10 flex grow flex-col items-center justify-center px-6 py-16 text-center lg:px-12">
        <div className="flex items-center gap-3.5">
          <span className="hidden h-px w-7 sm:block" style={{ background: AMBER }} />
          <span
            className="text-[11px] font-bold uppercase tracking-[0.3em] sm:text-[13px]"
            style={{ color: AMBER }}
          >
            Hotel &amp; Resort Management, Simplified
          </span>
          <span className="hidden h-px w-7 sm:block" style={{ background: AMBER }} />
        </div>

        <h1
          className="mt-10 max-w-[820px] text-balance text-[2.25rem] font-extrabold leading-[1.14] tracking-[-0.02em] sm:text-[3rem] lg:text-[4rem]"
          style={{ color: '#ffffff' }}
        >
          One Dashboard.
          <br />
          Every Property.
        </h1>

        <p
          className="mt-8 max-w-[660px] text-base font-medium leading-[1.55] sm:text-lg lg:text-[1.375rem]"
          style={{ color: SUBHEAD }}
        >
          Owner insights, front-desk operations, inventory, and staff — unified in a single
          hotel &amp; resort platform.
        </p>

        {/* No destination yet — a placeholder for whatever sign-up flow comes
            later. Inert on purpose rather than pointing somewhere wrong. */}
        <button
          type="button"
          className="mt-12 rounded-lg px-7 py-3 text-base font-bold transition-opacity hover:opacity-90"
          style={{ background: AMBER, color: INK }}
        >
          Get Started
        </button>

        <ul className="mt-16 flex list-none flex-wrap items-center justify-center gap-x-8 gap-y-3 p-0">
          {COVERS.map((c) => (
            <li
              key={c}
              className="flex items-center gap-2 text-xs font-semibold uppercase tracking-[0.16em]"
              style={{ color: 'rgba(199,204,214,0.7)' }}
            >
              <span className="h-1 w-1 rounded-full" style={{ background: AMBER }} />
              {c}
            </li>
          ))}
        </ul>
      </main>

      <footer
        className="relative z-10 px-6 py-6 text-center text-sm lg:px-12"
        style={{ color: 'rgba(199,204,214,0.55)' }}
      >
        <span className="mr-2">&copy; {new Date().getFullYear()} StayVanta</span>·
        <Link to="/privacy" className="mx-2 no-underline hover:underline" style={{ color: 'inherit' }}>
          Privacy Policy
        </Link>
        ·
        <Link to="/terms" className="ml-2 no-underline hover:underline" style={{ color: 'inherit' }}>
          Terms of Service
        </Link>
      </footer>
    </div>
  )
}
