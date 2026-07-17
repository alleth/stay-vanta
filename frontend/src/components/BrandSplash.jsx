// Full-screen animated "StayVanta" wordmark shown while the app boots
// (session resolution in AuthContext). The same splash is inlined in
// index.html so the pre-JS gap shows it too — keep the two in sync.
const LETTERS = [...'StayVanta']

export default function BrandSplash() {
  return (
    <div
      role="status"
      aria-label="Loading StayVanta"
      className="flex min-h-screen flex-col items-center justify-center gap-6 bg-canvas"
    >
      <div className="sv-serif text-4xl" aria-hidden="true">
        {LETTERS.map((ch, i) => (
          <span
            key={i}
            className={`sv-splash-letter ${i >= 4 ? 'text-accent' : 'text-body'}`}
            style={{ animationDelay: `${i * 70}ms` }}
          >
            {ch}
          </span>
        ))}
      </div>
      <div className="relative h-1 w-40 overflow-hidden rounded-full bg-subtle" aria-hidden="true">
        <div className="sv-splash-bar absolute inset-y-0 w-1/3 rounded-full bg-accent" />
      </div>
    </div>
  )
}
