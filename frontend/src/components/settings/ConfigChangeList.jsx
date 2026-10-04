import { Badge } from '../ui'
import {
  IMPACTS, actorText, changeLines, eventLabel, subjectText,
} from '../../utils/configChanges'

const fmtWhen = (s) => (s ? new Date(s).toLocaleString() : '—')

/**
 * Configuration changes as a timeline (build step 9): what changed, its
 * impact, who and when, why, and each field before → after. Used by the
 * Settings change log and by each row's history (`showSubject` off: the row
 * is already known).
 */
export default function ConfigChangeList({ changes, roomName, showSubject = true }) {
  return (
    <ol className="m-0 list-none space-y-2 p-0">
      {changes.map((c) => {
        const impact = IMPACTS[c.impact]
        const who = actorText(c)
        const baseline = c.event === 'baseline_recorded'
        return (
          <li key={c.id}
            className={`border-l-2 pl-3 text-sm ${c.impact === 'price' && !baseline ? 'border-accent' : 'border-line'}`}>
            <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
              <span className="font-semibold">
                {eventLabel(c.event)}{showSubject && ` · ${subjectText(c)}`}
              </span>
              {impact && !baseline && <Badge bg={impact.variant}>{impact.label}</Badge>}
            </div>
            <div className="text-xs text-muted">{who} · {fmtWhen(c.at)}</div>
            {c.reason && <div className="text-xs">Reason: {c.reason}</div>}
            {changeLines(c, roomName).map((l) => <div key={l} className="text-xs text-muted">{l}</div>)}
            {baseline && (
              <div className="text-xs text-muted">
                Its values when changes started being recorded; who set them and when wasn&apos;t recorded.
              </div>
            )}
          </li>
        )
      })}
    </ol>
  )
}
