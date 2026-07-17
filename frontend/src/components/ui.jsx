// Tailwind-only UI kit. These components mirror the (small) subset of the
// react-bootstrap API the pages were written against — variant/size props,
// Modal show/onHide, Form.* compound components — so the call sites keep
// their shape, but every style below is a plain Tailwind class. Bootstrap's
// CSS is not loaded anywhere.
import { Children, createContext, useContext, useEffect, useState } from 'react'

const cx = (...parts) => parts.filter(Boolean).join(' ')

// Apply a default utility only when the caller didn't pass a conflicting one
// (plain class concatenation can't reliably resolve Tailwind conflicts).
const unless = (className, re, def) => (re.test(className) ? '' : def)
const PAD = /(^|\s)p[xytrbl]?-/
const MB = /(^|\s)mb-/
const B0 = /(^|\s)border-0(\s|$)/

/* ---------------------------------------------------------------- Button */

const BUTTON_VARIANTS = {
  primary: 'border-transparent bg-ink text-white hover:bg-ink-hover',
  secondary: 'border-line bg-subtle text-body hover:bg-line',
  success: 'border-transparent bg-emerald-600 text-white hover:bg-emerald-700',
  danger: 'border-transparent bg-red-600 text-white hover:bg-red-700',
  warning: 'border-transparent bg-accent text-white hover:bg-[#c0800c]',
  'outline-primary': 'border-line bg-transparent text-ink hover:bg-subtle',
  'outline-secondary': 'border-line bg-transparent text-body hover:bg-subtle',
  'outline-success': 'border-emerald-300 bg-transparent text-emerald-700 hover:bg-emerald-50',
  'outline-danger': 'border-red-300 bg-transparent text-red-600 hover:bg-red-50',
  link: 'border-transparent bg-transparent text-ink hover:underline',
}

export function Button({
  variant = 'primary', size, as: Comp = 'button', type, disabled,
  className = '', children, ...rest
}) {
  return (
    <Comp
      type={Comp === 'button' ? type ?? 'button' : type}
      disabled={disabled}
      className={cx(
        'inline-flex items-center justify-center gap-1.5 border font-medium transition-colors',
        size === 'sm' ? 'rounded-md px-2.5 py-1 text-sm' : 'rounded-lg px-3.5 py-2 text-sm',
        BUTTON_VARIANTS[variant] ?? BUTTON_VARIANTS.primary,
        disabled && 'pointer-events-none opacity-60',
        className,
      )}
      {...rest}
    >
      {children}
    </Comp>
  )
}

/* --------------------------------------------------------------- Spinner */

export function Spinner({ size, className = '' }) {
  return (
    <span
      role="status"
      aria-label="loading"
      className={cx(
        'inline-block animate-spin rounded-full border-2 border-current border-r-transparent align-[-0.125em]',
        size === 'sm' ? 'h-4 w-4' : 'h-6 w-6',
        className,
      )}
    />
  )
}

/* ----------------------------------------------------------------- Badge */

const BADGE_VARIANTS = {
  primary: 'bg-ink text-white',
  secondary: 'bg-subtle text-[#5b6270]',
  success: 'bg-emerald-100 text-emerald-800',
  danger: 'bg-red-100 text-red-700',
  warning: 'bg-amber-100 text-amber-800',
  info: 'bg-sky-100 text-sky-800',
  dark: 'bg-gray-800 text-white',
  light: 'bg-gray-100 text-gray-700',
}

export function Badge({ bg = 'secondary', className = '', children }) {
  return (
    <span className={cx(
      'inline-block whitespace-nowrap rounded-full px-2 py-0.5 text-xs font-medium',
      BADGE_VARIANTS[bg] ?? BADGE_VARIANTS.secondary,
      className,
    )}>
      {children}
    </span>
  )
}

/* ------------------------------------------------------------------ Card */

export function Card({ className = '', style, children }) {
  return (
    <div style={style} className={cx('rounded-xl border border-line bg-surface', className)}>
      {children}
    </div>
  )
}

function CardHeader({ className = '', children }) {
  return (
    <div className={cx('border-b border-line font-semibold', unless(className, PAD, 'px-4 py-3'), className)}>
      {children}
    </div>
  )
}

function CardBody({ className = '', style, children }) {
  return <div style={style} className={cx(unless(className, PAD, 'p-4'), className)}>{children}</div>
}

function CardFooter({ className = '', children }) {
  return (
    <div className={cx('border-t border-line', unless(className, PAD, 'px-4 py-3'), className)}>
      {children}
    </div>
  )
}

Card.Header = CardHeader
Card.Body = CardBody
Card.Footer = CardFooter

/* ----------------------------------------------------------------- Table */

// Cell/heading styles live on .sv-table in index.css so every <th>/<td>
// doesn't need its own class soup. `responsive` is implied (always scrolls).
export function Table({ hover, className = '', children }) {
  return (
    <div className="overflow-x-auto rounded-[inherit]">
      <table className={cx('sv-table', hover && 'sv-table-hover', className)}>{children}</table>
    </div>
  )
}

/* ----------------------------------------------------------------- Modal */

const ModalCtx = createContext(null)

export function Modal({ show = true, onHide, size, children }) {
  useEffect(() => {
    if (!show) return undefined
    const onKey = (e) => { if (e.key === 'Escape') onHide?.() }
    document.addEventListener('keydown', onKey)
    const prev = document.body.style.overflow
    document.body.style.overflow = 'hidden'
    return () => {
      document.removeEventListener('keydown', onKey)
      document.body.style.overflow = prev
    }
  }, [show, onHide])

  if (!show) return null
  const width = size === 'xl' ? 'max-w-5xl' : size === 'lg' ? 'max-w-3xl' : 'max-w-lg'

  return (
    <ModalCtx.Provider value={onHide}>
      <div className="fixed inset-0 z-50 overflow-y-auto bg-black/40">
        <div
          className="flex min-h-full items-center justify-center p-4"
          onMouseDown={(e) => { if (e.target === e.currentTarget) onHide?.() }}
        >
          <div className={cx('w-full rounded-xl bg-surface shadow-xl', width)}>{children}</div>
        </div>
      </div>
    </ModalCtx.Provider>
  )
}

function ModalHeader({ closeButton, className = '', children }) {
  const onHide = useContext(ModalCtx)
  return (
    <div className={cx(
      'flex items-center justify-between gap-3',
      unless(className, PAD, 'px-4 py-3'),
      unless(className, B0, 'border-b border-line'),
      className,
    )}>
      <div className="min-w-0">{children}</div>
      {closeButton && (
        <button
          type="button"
          aria-label="Close"
          onClick={onHide}
          className="-mr-1 rounded-md px-2 py-0.5 text-xl leading-none text-muted hover:bg-subtle hover:text-body"
        >
          ×
        </button>
      )}
    </div>
  )
}

function ModalTitle({ className = '', children }) {
  return <h2 className={cx('text-lg font-semibold', className)}>{children}</h2>
}

function ModalBody({ className = '', children }) {
  return <div className={cx(unless(className, PAD, 'p-4'), className)}>{children}</div>
}

function ModalFooter({ className = '', children }) {
  return (
    <div className={cx(
      'flex items-center justify-end gap-2',
      unless(className, PAD, 'px-4 py-3'),
      unless(className, B0, 'border-t border-line'),
      className,
    )}>
      {children}
    </div>
  )
}

Modal.Header = ModalHeader
Modal.Title = ModalTitle
Modal.Body = ModalBody
Modal.Footer = ModalFooter

/* ------------------------------------------------------------------ Form */

export function Form({ className = '', children, ...rest }) {
  return <form className={className} {...rest}>{children}</form>
}

function FormGroup({ className = '', children }) {
  return <div className={className}>{children}</div>
}

function FormLabel({ className = '', children }) {
  return (
    <label className={cx('block text-sm font-medium', unless(className, MB, 'mb-1'), className)}>
      {children}
    </label>
  )
}

const CONTROL =
  'block w-full rounded-lg border border-line bg-surface text-body placeholder:text-muted ' +
  'focus:border-accent focus:outline-none focus:ring-[3px] focus:ring-accent/15 ' +
  'disabled:bg-subtle disabled:text-muted'

function FormControl({ as, size, type = 'text', className = '', ...rest }) {
  const pad = size === 'sm' ? 'px-2.5 py-1.5 text-sm' : 'px-3 py-2 text-sm'
  if (as === 'textarea') return <textarea className={cx(CONTROL, pad, className)} {...rest} />
  return <input type={type} className={cx(CONTROL, pad, className)} {...rest} />
}

function FormSelect({ size, className = '', children, ...rest }) {
  return (
    <select
      className={cx(CONTROL, size === 'sm' ? 'px-2 py-1.5 text-sm' : 'px-3 py-2 text-sm', className)}
      {...rest}
    >
      {children}
    </select>
  )
}

function FormCheck({ type = 'checkbox', id, label, className = '', ...rest }) {
  return (
    <label htmlFor={id} className={cx('inline-flex items-center gap-2 text-sm', className)}>
      <input id={id} type={type} className="h-4 w-4 rounded border-line accent-ink" {...rest} />
      {label}
    </label>
  )
}

function FormText({ muted, className = '', children }) {
  return (
    <div className={cx(
      'mt-1 text-xs',
      (muted || !/(^|\s)text-(red|amber|emerald|sky|gray)/.test(className)) && 'text-muted',
      className,
    )}>
      {children}
    </div>
  )
}

Form.Group = FormGroup
Form.Label = FormLabel
Form.Control = FormControl
Form.Select = FormSelect
Form.Check = FormCheck
Form.Text = FormText

/* ----------------------------------------------------------------- Alert */

const ALERT_VARIANTS = {
  danger: 'border-red-200 bg-red-50 text-red-800',
  success: 'border-emerald-200 bg-emerald-50 text-emerald-800',
  warning: 'border-amber-200 bg-amber-50 text-amber-900',
  info: 'border-sky-200 bg-sky-50 text-sky-900',
  secondary: 'border-line bg-subtle text-body',
}

export function Alert({ variant = 'info', dismissible, onClose, className = '', children }) {
  return (
    <div
      role="alert"
      className={cx(
        'rounded-lg border text-sm',
        unless(className, PAD, 'px-4 py-3'),
        unless(className, MB, 'mb-4'),
        ALERT_VARIANTS[variant] ?? ALERT_VARIANTS.info,
        dismissible && 'relative pr-10',
        className,
      )}
    >
      {children}
      {dismissible && (
        <button
          type="button"
          aria-label="Close"
          onClick={onClose}
          className="absolute right-2 top-2 rounded-md px-1.5 text-lg leading-none opacity-60 hover:opacity-100"
        >
          ×
        </button>
      )}
    </div>
  )
}

/* ------------------------------------------------------------- Tabs / Tab */

// <Tab> is a marker: Tabs reads eventKey/title off each child and renders
// only the active tab's children (tab state lives in the parent pages, so
// nothing is lost when switching).
export function Tab({ children }) {
  return children
}

export function Tabs({ defaultActiveKey, className = '', children }) {
  const items = Children.toArray(children)
  const [active, setActive] = useState(defaultActiveKey ?? items[0]?.props?.eventKey)
  const current = items.find((t) => t.props.eventKey === active) ?? items[0]
  return (
    <div>
      <div role="tablist" className={cx('flex flex-wrap gap-1 border-b border-line', className)}>
        {items.map((t) => (
          <button
            key={t.props.eventKey}
            role="tab"
            type="button"
            aria-selected={active === t.props.eventKey}
            onClick={() => setActive(t.props.eventKey)}
            className={cx(
              '-mb-px border-b-2 px-3.5 py-2 text-sm font-medium transition-colors',
              active === t.props.eventKey
                ? 'border-ink text-body'
                : 'border-transparent text-muted hover:text-body',
            )}
          >
            {t.props.title}
          </button>
        ))}
      </div>
      <div>{current?.props?.children}</div>
    </div>
  )
}

/* ------------------------------------------------------------ InputGroup */

// .sv-group (index.css) collapses inner radii and overlaps shared borders.
export function InputGroup({ className = '', style, children }) {
  return <div style={style} className={cx('sv-group w-full', className)}>{children}</div>
}

function InputGroupText({ className = '', children }) {
  return (
    <span className={cx('flex items-center border border-line bg-subtle px-3 text-sm text-muted', className)}>
      {children}
    </span>
  )
}

InputGroup.Text = InputGroupText

/* ----------------------------------------------------------- ButtonGroup */

export function ButtonGroup({ className = '', children }) {
  return <div className={cx('sv-group', className)}>{children}</div>
}

/* ------------------------------------------------------------ Pagination */

export function Pagination({ className = '', children }) {
  return <nav className={cx('flex items-center gap-1', className)}>{children}</nav>
}

function PaginationBtn({ children, ...rest }) {
  return (
    <button
      type="button"
      className="rounded-md border border-line bg-surface px-2.5 py-1 text-sm hover:bg-subtle disabled:pointer-events-none disabled:opacity-50"
      {...rest}
    >
      {children}
    </button>
  )
}

function PaginationPrev(props) {
  return <PaginationBtn aria-label="Previous page" {...props}>‹</PaginationBtn>
}

function PaginationNext(props) {
  return <PaginationBtn aria-label="Next page" {...props}>›</PaginationBtn>
}

Pagination.Prev = PaginationPrev
Pagination.Next = PaginationNext

/* ------------------------------------------------------------- ListGroup */

export function ListGroup({ className = '', children }) {
  return <div className={cx('divide-y divide-line', className)}>{children}</div>
}

function ListGroupItem({ className = '', children }) {
  return <div className={cx('text-sm', unless(className, PAD, 'px-4 py-3'), className)}>{children}</div>
}

ListGroup.Item = ListGroupItem
