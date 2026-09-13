import { useTheme } from '../context/ThemeContext'
import { MoonIcon, SunIcon } from './icons'

// Light/dark switch for the header (and the login screen, which renders
// outside Layout). A bare <button> rather than the kit's <Button> on purpose:
// this is the same square icon-affordance shape the Dropdown trigger in
// ui.jsx uses, and no Button variant covers it.
//
// The icon shows the theme you'd switch *to*, and the label says so out loud
// — an icon alone is ambiguous about which half it means.
export default function ThemeToggle({ className = '' }) {
  const { theme, toggle } = useTheme()
  const next = theme === 'dark' ? 'light' : 'dark'

  return (
    <button
      type="button"
      onClick={toggle}
      title={`Switch to ${next} mode`}
      aria-label={`Switch to ${next} mode`}
      className={`inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg text-muted transition-colors hover:bg-subtle hover:text-body ${className}`}
    >
      {theme === 'dark' ? <SunIcon className="h-4 w-4" /> : <MoonIcon className="h-4 w-4" />}
    </button>
  )
}
