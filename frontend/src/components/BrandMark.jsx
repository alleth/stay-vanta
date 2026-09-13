// The StayVanta mark — an ink upstroke crossing an amber chevron. Geometry is
// taken verbatim from the design reference the favicon was cut from
// (public/favicon.svg), so the tab icon and the in-app logo stay the same
// drawing.
//
// It lives here rather than in icons.jsx because that file's icons are all
// one-colour 1.6px-stroke line glyphs sharing a BASE; the mark is two colours
// at stroke-width 8 and follows none of it. The colours are the theme tokens
// read straight from CSS, so the ink half inverts with the rest of the app in
// dark mode instead of vanishing.
export default function BrandMark({ className }) {
  return (
    <svg
      viewBox="0 0 64 64"
      className={className}
      role="img"
      aria-label="StayVanta"
    >
      <g
        transform="translate(32,32) scale(0.9) translate(-32,-32)"
        fill="none"
        strokeWidth="8"
        strokeLinecap="butt"
        strokeLinejoin="miter"
      >
        <path stroke="var(--color-body)" d="M44.85,23.51 L32,10.66 L21.98,20.68 L34.71,33.41" />
        <path stroke="var(--color-accent)" d="M7.83,29.17 L32,53.34 L56.17,29.17" />
      </g>
    </svg>
  )
}
