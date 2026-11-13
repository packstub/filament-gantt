/**
 * packstub/filament-gantt — the chart in the browser.
 *
 * The chart is state (a window, groups, bars) drawn by Alpine. All the maths is in
 * one pure function, layout(): it turns bars and a window into pixel columns and
 * rows. A drag (or an arrow key) changes the bar at once and asks the server
 * afterwards; if the server refuses, the bar goes back and the reason is shown.
 * Pointer events only, no library. Hand-written ES module; build.mjs minifies it into resources/dist.
 *
 * Dates are naive "YYYY-MM-DD HH:MM" strings, read and written as UTC timestamps
 * so daylight-saving changes never shift a bar (the server reads them the same way).
 * A bar is a half-open range [start, end); a milestone has start == end.
 */

export const DAY = 86400000

export const HOUR = 3600000

const TONES = {
    gray: '#71717a', slate: '#64748b', zinc: '#71717a', stone: '#78716c',
    red: '#ef4444', orange: '#f97316', amber: '#f59e0b', yellow: '#eab308',
    lime: '#84cc16', green: '#22c55e', emerald: '#10b981', teal: '#14b8a6',
    cyan: '#06b6d4', sky: '#0ea5e9', blue: '#3b82f6', indigo: '#6366f1',
    violet: '#8b5cf6', purple: '#a855f7', fuchsia: '#d946ef', pink: '#ec4899', rose: '#f43f5e',
    primary: 'var(--primary-500)', success: 'var(--success-500)', warning: 'var(--warning-500)',
    danger: 'var(--danger-500)', info: 'var(--info-500)',
}

const MILESTONE_PX = 14
const LONG_PRESS_MS = 450
// A bar narrower than this has no resize handles: the 8px handles would cover it,
// so a drag anywhere on it moves it (resize it from the keyboard, or zoom in).
const HANDLES_MIN_PX = 24
// Arrow keys pressed in a row become one move, sent once the keys rest.
const NUDGE_MS = 600
// How close to the chart's edge a dragged bar starts scrolling it, and how fast.
const EDGE_PX = 48
const EDGE_MAX_STEP = 18
// Above this many columns (hours over months) only the ones in view are drawn.
const COLUMNS_IN_DOM = 400
// A zoom level's pixels per day may be stretched this far either way before the next level takes over
const ZOOM_MIN = 0.5
const ZOOM_MAX = 2
// How far an arrow leaves a bar before it turns
const ARROW_GAP = 8

/** A URL safe to navigate to: a path, or http(s), mailto, tel. Never javascript:, data:, vbscript:, nor a protocol-relative //host. */
export function safeUrl(url) {
    const value = String(url ?? '').trim()
    if (! value) return null
    // As a browser reads it: control characters and spaces removed.
    const bare = value.replace(/[\u0000- ]+/g, '')
    // Two leading slashes of either kind name a host: an open redirect, not a path.
    if (/^[\/\\]{2}/.test(bare)) return null
    const m = /^([a-z][a-z0-9+.-]*):/i.exec(bare)

    return ! m || ['http', 'https', 'mailto', 'tel'].includes(m[1].toLowerCase()) ? value : null
}

/**
 * A colour as CSS, or null. The same shapes the server keeps (Bar::safeColor()): a
 * name, #hex, a colour function of plain values, var(--name). A name the chart does
 * not know is the panel's own colour of that name (`brand` → --brand-500), or the
 * CSS colour of that name when the panel has none.
 */
export function tone(color) {
    const value = String(color ?? '').trim()
    if (! value) return TONES.primary
    if (TONES[value]) return TONES[value]
    if (/^[a-z][a-z0-9-]*$/i.test(value)) return `var(--${value}-500, ${value})`
    if (/^#(?:[0-9a-f]{3,4}|[0-9a-f]{6}|[0-9a-f]{8})$/i.test(value)) return value
    if (/^(?:rgba?|hsla?|hwb|lab|lch|oklab|oklch|color)\([a-z0-9.,%\/+\- ]*\)$/i.test(value)) return value
    if (/^var\(--[a-z0-9_-]+\)$/i.test(value)) return value

    return TONES.primary
}

/* ------------------------------------------------------------------ dates */

/** "YYYY-MM-DD", "YYYY-MM-DD HH:MM[:SS]" or "…THH:MM" → a UTC timestamp (null when unreadable). */
export function parse(value) {
    const m = /^(\d{4})-(\d{2})-(\d{2})(?:[T ](\d{2}):(\d{2})(?::(\d{2}))?)?/.exec(String(value ?? ''))

    return m ? Date.UTC(+m[1], +m[2] - 1, +m[3], +(m[4] || 0), +(m[5] || 0), +(m[6] || 0)) : null
}

/** A UTC timestamp → "YYYY-MM-DD HH:MM" (or the date alone). */
export function format(ts, withTime = true) {
    const d = new Date(ts)
    const pad = (n) => String(n).padStart(2, '0')
    const date = `${d.getUTCFullYear()}-${pad(d.getUTCMonth() + 1)}-${pad(d.getUTCDate())}`

    return withTime ? `${date} ${pad(d.getUTCHours())}:${pad(d.getUTCMinutes())}` : date
}

/** ISO weekday of a UTC timestamp: 1 = Monday … 7 = Sunday. */
/** A `Y-m-d` string naming a real calendar date (a picker's `Y-m-d H:i:s` cut to its day), or null: as Filter::date() on the server. */
export function isoDay(value) {
    const m = typeof value === 'string' && value.match(/^(\d{4})-(\d{2})-(\d{2})/)
    if (! m || +m[1] < 1000) return null
    const d = new Date(Date.UTC(+m[1], +m[2] - 1, +m[3]))
    return d.getUTCFullYear() === +m[1] && d.getUTCMonth() === +m[2] - 1 && d.getUTCDate() === +m[3] ? m[1] + '-' + m[2] + '-' + m[3] : null
}

export function isoWeekday(ts) {
    return ((new Date(ts).getUTCDay() + 6) % 7) + 1
}

/** A PHP date format ("M j, Y", "D d/m H:i") applied to a UTC timestamp, month and day names in the locale. */
export function phpDate(ts, pattern, locale = 'en') {
    const d = new Date(ts)
    const pad = (n) => String(n).padStart(2, '0')
    const name = (options) => new Intl.DateTimeFormat(locale, { ...options, timeZone: 'UTC' }).format(d)
    const h24 = d.getUTCHours()
    const h12 = h24 % 12 || 12
    const tokens = {
        d: pad(d.getUTCDate()), j: String(d.getUTCDate()),
        D: name({ weekday: 'short' }), l: name({ weekday: 'long' }), N: String(isoWeekday(ts)),
        m: pad(d.getUTCMonth() + 1), n: String(d.getUTCMonth() + 1),
        M: name({ month: 'short' }), F: name({ month: 'long' }),
        y: String(d.getUTCFullYear()).slice(-2), Y: String(d.getUTCFullYear()),
        H: pad(h24), G: String(h24), h: pad(h12), g: String(h12),
        i: pad(d.getUTCMinutes()), s: pad(d.getUTCSeconds()),
        a: h24 < 12 ? 'am' : 'pm', A: h24 < 12 ? 'AM' : 'PM',
    }
    let out = ''

    for (let i = 0; i < pattern.length; i++) {
        const c = pattern[i]
        if (c === '\\' && i + 1 < pattern.length) { out += pattern[++i]; continue }
        out += tokens[c] ?? c
    }

    return out
}

/** About how wide a short label is drawn in the axis (11px text, 5.6px a character, the cell's padding), so one that does not fit is left out. */
function fits(label, width) {
    return String(label).length * 5.6 + 8 <= width
}

/** The time part of a PHP date format ("M j, Y H:i" → "H:i"), or null when no date part leads it. */
export function timePattern(pattern) {
    const m = /^(.*[dDjlNSwzWFmMntLoYy][^GgHhisuvaAeIOPpTZ]*?)\s+([GgHhisuvaAeIOPpTZ:.\s]+)$/.exec(pattern)

    return m ? m[2] : null
}

/* ------------------------------------------------------------------ layout */

/**
 * The whole chart as pixels, from the bars and the window. Pure: same input, same
 * output, nothing touched.
 *
 * bars:   [{ id, group, start, end, milestone, label, color, progress, movable, resizable, startFixed, … }]
 * window: {
 *   start, end            "YYYY-MM-DD", both days included
 *   today                 "YYYY-MM-DD"
 *   now                   "YYYY-MM-DD HH:MM", the line at the hour scale
 *   pxPerDay              pixels per day at this scale
 *   rowHeight             pixels
 *   firstDayOfWeek        1 = Monday … 7 = Sunday
 *   weekendDays           ISO weekdays shaded
 *   locale                for month and day names
 *   groups                [{ key, label, color, collapsed, count }] in order (may be empty)
 *   grouped               whether group rows are drawn
 *   compact               one row per group, overlapping bars stacked in lanes
 *   lineLayout            lineBy(): the bars of one line (bar.line) share a row, overlapping ones stacked
 *   lines                 Map of line key → { key, label, pieces } for the lines holding several pieces
 *   rowLayout             rowBy(): the bars of one row (bar.row) share it, overlapping ones stacked
 *   rows                  [{ key, label, color, group, listed, actions }] the rows in order: listed ones (rows(); group null: in every group), then those met on the bars
 *   unassignedLabel       the name of the row holding the bars without a row
 *   unassignedRow         that row shows in every group, empty or not (default: only where a bar has no row)
 *   collapsed             { [group key]: bool } overriding the groups' own flag
 * }
 * scale:  'hour' | 'day' | 'week' | 'month' | 'year'
 *
 * Returns { start, days, width, pxPerDay, rowHeight, columns, top, shades, todayX, rows } where
 * rows are { type: 'group' | 'bar' | 'lanes' | 'row', key, group, label, height, collapsed, bars, span, line, row }
 * and each row bar is the source bar plus { s, e, x, w, lane, src }. A line's row is a
 * 'bar' row holding its pieces, `line` its key (every 'bar' row has one in the line
 * layout: creating there adds a piece). A row of rowBy() is a 'row' row, `row` its key and
 * `color` its dot; the bars without a row share the unassigned row, the group's last (`row` ''). A parent task's
 * own bar (`bar.parent`: its span) comes first in its row's bars, in no lane (`lanes` is the row's count: it is drawn
 * behind the others over the whole height), and the row carries the parent's `actions`. An axis label that does not fit its cell is empty. A folded group's `span` is
 * { x, w, s, e, allDay, milestone }: the line from its first bar to its last, so the
 * row still shows where the group sits. Open groups, and groups with no bars, have none.
 */
export function layout(bars, window, scale) {
    const start = parse(window.start)
    const last = parse(window.end)
    const ppd = window.pxPerDay
    const rowHeight = window.rowHeight
    const days = Math.max(1, Math.round((last - start) / DAY) + 1)
    const end = start + days * DAY
    const width = days * ppd
    const today = parse(window.today)
    const weekend = new Set(window.weekendDays || [])
    const monthName = (ts, long) => new Intl.DateTimeFormat(window.locale || 'en', { month: long ? 'long' : 'short', timeZone: 'UTC' }).format(new Date(ts))
    const weekdayName = (ts) => new Intl.DateTimeFormat(window.locale || 'en', { weekday: 'short', timeZone: 'UTC' }).format(new Date(ts))
    const x = (ts) => ((ts - start) / DAY) * ppd
    // A month cut short by the window edge gets a short label, so it never runs into the next one.
    const monthLabel = (m) => m.w >= 120 ? `${monthName(m.ts, true)} ${m.year}` : monthName(m.ts)
    // A label that does not fit its cell falls back to its short form (the day number), then to nothing.
    const cell = ({ short, ...c }) => ({ ...c, label: fits(c.label, c.w) ? c.label : (short !== undefined && fits(short, c.w) ? short : '') })

    // Segments of whole months and years clipped to the window.
    const months = []
    for (let d = new Date(start); d.getTime() < end; d = new Date(Date.UTC(d.getUTCFullYear(), d.getUTCMonth() + 1, 1))) {
        const from = Math.max(d.getTime(), start)
        const to = Math.min(Date.UTC(d.getUTCFullYear(), d.getUTCMonth() + 1, 1), end)
        months.push({ key: 'm' + from, x: x(from), w: x(to) - x(from), ts: from, year: d.getUTCFullYear(), month: d.getUTCMonth() })
    }
    const years = []
    for (let d = new Date(start); d.getTime() < end; d = new Date(Date.UTC(d.getUTCFullYear() + 1, 0, 1))) {
        const from = Math.max(d.getTime(), start)
        const to = Math.min(Date.UTC(d.getUTCFullYear() + 1, 0, 1), end)
        years.push({ key: 'y' + from, x: x(from), w: x(to) - x(from), label: String(d.getUTCFullYear()) })
    }

    let columns = []
    let top = []
    let shades = []

    if (scale === 'hour') {
        const pph = ppd / 24
        for (let i = 0; i < days * 24; i++) {
            const ts = start + i * HOUR
            columns.push({ key: 'c' + ts, x: i * pph, w: pph, label: String(new Date(ts).getUTCHours()).padStart(2, '0'), weekend: false, today: false })
        }
        for (let i = 0; i < days; i++) {
            const ts = start + i * DAY
            top.push({ key: 'd' + ts, x: i * ppd, w: ppd, label: `${weekdayName(ts)} ${new Date(ts).getUTCDate()} ${monthName(ts)}` })
        }
    } else if (scale === 'day') {
        for (let i = 0; i < days; i++) {
            const ts = start + i * DAY
            columns.push({ key: 'c' + ts, x: i * ppd, w: ppd, label: `${weekdayName(ts)} ${new Date(ts).getUTCDate()}`, short: String(new Date(ts).getUTCDate()), weekend: weekend.has(isoWeekday(ts)), today: ts === today })
        }
        top = months.map((m) => ({ key: m.key, x: m.x, w: m.w, label: monthLabel(m) }))
    } else if (scale === 'week') {
        let i = 0
        while (i < days) {
            const ts = start + i * DAY
            const len = Math.min(((window.firstDayOfWeek || 1) - isoWeekday(ts) + 7) % 7 || 7, days - i)
            columns.push({ key: 'c' + ts, x: i * ppd, w: len * ppd, label: `${new Date(ts).getUTCDate()} ${monthName(ts)}`, short: String(new Date(ts).getUTCDate()), weekend: false, today: false })
            i += len
        }
        top = months.map((m) => ({ key: m.key, x: m.x, w: m.w, label: monthLabel(m) }))
    } else if (scale === 'year') {
        // Months over quarters: a plan of a year or two in one screen.
        columns = months.map((m) => ({ key: m.key, x: m.x, w: m.w, label: monthName(m.ts), weekend: false, today: false }))
        for (let d = new Date(start); d.getTime() < end; d = new Date(Date.UTC(d.getUTCFullYear(), Math.floor(d.getUTCMonth() / 3) * 3 + 3, 1))) {
            const from = Math.max(d.getTime(), start)
            const to = Math.min(Date.UTC(d.getUTCFullYear(), Math.floor(d.getUTCMonth() / 3) * 3 + 3, 1), end)
            top.push({ key: 'q' + from, x: x(from), w: x(to) - x(from), label: `Q${Math.floor(d.getUTCMonth() / 3) + 1} ${d.getUTCFullYear()}`, short: `Q${Math.floor(d.getUTCMonth() / 3) + 1}` })
        }
    } else {
        columns = months.map((m) => ({ key: m.key, x: m.x, w: m.w, label: monthName(m.ts), weekend: false, today: false }))
        top = years
    }

    columns = columns.map(cell)
    top = top.map(cell)

    if (scale !== 'month' && scale !== 'year') {
        for (let i = 0; i < days; i++) {
            const ts = start + i * DAY
            if (weekend.has(isoWeekday(ts))) {
                shades.push({ key: 's' + ts, x: i * ppd, w: ppd })
            }
        }
    }

    // At the hour scale the line is now, not the start of today.
    const now = scale === 'hour' && window.now ? parse(window.now) : null
    const mark = now ?? today
    const todayX = mark !== null && mark >= start && mark < end ? x(mark) : null

    // Groups in the definition's order, then any met on a bar.
    const groups = []
    const byKey = new Map()
    for (const g of window.groups || []) {
        const group = { ...g, count: 0 }
        groups.push(group)
        byKey.set(String(g.key), group)
    }
    const placed = []
    for (const bar of bars) {
        const s = parse(bar.start)
        if (s === null) continue
        const e = bar.milestone ? s : Math.max(parse(bar.end) ?? s, s)
        const key = String(bar.group ?? '')
        let group = byKey.get(key)
        if (! group) {
            group = { key, label: key || '', color: null, collapsed: false, count: 0 }
            groups.push(group)
            byKey.set(key, group)
        }
        // A parent's computed span (ParentSpan::FromChildren) is no record: never counted.
        if (! bar.computed) group.count++
        placed.push({ ...bar, s, e, x: x(s), w: bar.milestone ? 0 : Math.max(x(e) - x(s), 4), lane: 0, src: bar })
    }
    if (groups.length === 0) {
        groups.push({ key: '', label: '', color: null, collapsed: false, count: 0 })
    }

    // A folded group hides its bars. The line keeps the extent: first start to last end.
    const spanOf = (bars) => {
        if (bars.length === 0) return null

        let s = Infinity
        let e = -Infinity

        for (const bar of bars) {
            if (bar.s < s) s = bar.s
            if (bar.e > e) e = bar.e
        }

        const x0 = x(s)

        return { x: x0, w: Math.max(x(e) - x0, 2), s, e, allDay: bars.every((bar) => bar.allDay), milestone: s === e }
    }

    // lineBy(): a group's bars by line, in the order of each line's first bar (the
    // server's order), one row per line; a line's label is its own record's, sent with
    // the lines that hold several pieces, else its bar's.
    const lineRows = (own, group) => {
        const buckets = new Map()
        for (const bar of own) {
            const line = bar.line === null || bar.line === undefined || bar.line === '' ? 'b:' + bar.id : String(bar.line)
            if (! buckets.has(line)) buckets.set(line, [])
            buckets.get(line).push(bar)
        }

        const out = []
        for (const [line, bucket] of buckets) {
            const entry = window.lines?.get?.(line) ?? null
            const lanes = bucket.length > 1 ? pack(bucket, 0) : 1
            out.push({
                type: 'bar',
                key: bucket.length > 1 || entry ? 'l:' + group.key + ':' + line : 'b:' + bucket[0].id,
                group,
                label: entry?.label ?? bucket[0].label ?? '',
                height: lanes * rowHeight,
                collapsed: false,
                bars: bucket,
                span: null,
                line: line.startsWith('b:') ? null : line,
            })
        }

        return out
    }

    // rowBy(): a group's rows, each holding its bars side by side, overlapping ones in
    // lanes: the listed rows of the group (and those listed without one) in their order,
    // then the rows met on its bars, then the unassigned row: the bars without a row.
    const rowIndex = new Map((window.rows || []).map((r) => [String(r.key), r]))
    // lineBy() inside a row: the pieces of a line share a lane, so their joiner runs
    // straight, unless they overlap each other (then each takes the lane it finds).
    const packRow = (bars) => {
        if (! window.lineLayout) return pack(bars)

        const units = []
        const byLine = new Map()
        for (const bar of bars) {
            const line = bar.line === null || bar.line === undefined || bar.line === '' ? null : String(bar.line)
            if (line === null) {
                units.push([bar])
                continue
            }
            if (! byLine.has(line)) {
                byLine.set(line, [])
                units.push(byLine.get(line))
            }
            byLine.get(line).push(bar)
        }

        const items = []
        for (const unit of units) {
            if (unit.length > 1 && pack(unit, 0) === 1) {
                const from = Math.min(...unit.map((b) => b.x))
                items.push({ x: from, w: Math.max(...unit.map((b) => b.x + b.w)) - from, milestone: false, unit })
            } else {
                for (const b of unit) items.push({ x: b.x, w: b.w, milestone: b.milestone, unit: [b] })
            }
        }

        const lanes = pack(items)
        for (const item of items) {
            for (const b of item.unit) b.lane = item.lane
        }

        return lanes
    }
    const rowRows = (own, group) => {
        const buckets = new Map()
        const loose = []
        for (const bar of own) {
            const key = String(bar.row ?? '')
            if (key === '') {
                loose.push(bar)
                continue
            }
            if (! buckets.has(key)) buckets.set(key, [])
            buckets.get(key).push(bar)
        }

        const order = []
        for (const r of window.rows || []) {
            if (r.listed && (r.group === null || r.group === undefined || String(r.group) === group.key)) order.push(String(r.key))
        }
        for (const key of buckets.keys()) {
            if (! order.includes(key)) order.push(key)
        }

        const keys = [...new Set(order)]
        // '' is no row: never a listed one's key.
        if (loose.length || window.unassignedRow) keys.push('')

        const out = []
        for (const key of keys) {
            const bucket = key === '' ? loose : (buckets.get(key) || [])
            const meta = key === '' ? { label: window.unassignedLabel ?? '', color: null } : rowIndex.get(key)
            // Parent tasks as rows: the parent's own bar is its span, behind its children and in no lane.
            const span = key === '' ? null : (bucket.find((b) => b.parent) ?? null)
            const held = span ? bucket.filter((b) => b !== span) : bucket
            const lanes = held.length ? packRow(held) : 1
            if (span) {
                span.lane = 0
                span.lanes = lanes
            }
            out.push({
                type: 'row',
                // The group's key may hold a colon: its length keeps two rows apart.
                key: 'r:' + group.key.length + ':' + group.key + ':' + key,
                group,
                label: meta?.label ?? key,
                color: meta?.color ?? null,
                height: lanes * rowHeight,
                collapsed: false,
                bars: span ? [span, ...held] : held,
                span: null,
                line: null,
                row: key,
                actions: Array.isArray(meta?.actions) ? meta.actions : null,
            })
        }

        return out
    }

    const rows = []
    for (const group of groups) {
        const own = placed.filter((b) => String(b.group ?? '') === group.key)
        const collapsed = !! (window.collapsed && group.key in window.collapsed ? window.collapsed[group.key] : group.collapsed)

        if (window.compact) {
            const lanes = pack(own)
            rows.push({ type: 'lanes', key: 'g:' + group.key, group, label: group.label, height: Math.max(1, lanes) * rowHeight, collapsed: false, bars: own, span: null })
            continue
        }

        if (window.grouped) {
            rows.push({ type: 'group', key: 'g:' + group.key, group, label: group.label, height: rowHeight, collapsed, bars: [], span: collapsed ? spanOf(own) : null })
        }

        if (! collapsed || ! window.grouped) {
            if (window.rowLayout) {
                rows.push(...rowRows(own, group))
            } else if (window.lineLayout) {
                rows.push(...lineRows(own, group))
            } else {
                for (const bar of own) {
                    rows.push({ type: 'bar', key: 'b:' + bar.id, group, label: bar.label ?? '', height: rowHeight, collapsed: false, bars: [bar] })
                }
            }
        }
    }

    return { start, days, width, pxPerDay: ppd, rowHeight, columns, top, shades, todayX, rows }
}

/**
 * The dependency arrows of a laid-out chart (see Gantt::dependencies()): one path per
 * (predecessor, successor) pair whose both bars are on the chart, from the predecessor's
 * end to the successor's start, finish-to-start. Pure, like layout(). `carried` is a bar
 * drawn over another row while it is dragged there ({ id, dy }): its arrows follow.
 *
 * Returns [{ key, d, late }], `late` when the successor starts before its predecessor ends.
 */
export function arrows(view, carried = null) {
    const rowHeight = view.rowHeight ?? 36
    const inner = rowHeight - 8
    const at = new Map()
    let y = 0
    for (const row of view.rows) {
        for (const bar of row.bars) {
            at.set(String(bar.id), { bar, y: y + 4 + (bar.lane || 0) * rowHeight + inner / 2, bottom: y + row.height })
        }
        y += row.height
    }
    const ids = carried ? new Set((carried.ids ?? [carried.id]).map(String)) : null

    const out = []
    for (const { bar, y: y2 } of at.values()) {
        for (const dep of bar.deps || []) {
            const from = at.get(String(dep))
            if (! from || from.bar === bar) continue
            const shift = (b) => ids?.has(String(b.id)) ? carried.dy : 0
            const x1 = from.bar.x + from.bar.w
            const y1 = from.y + shift(from.bar)
            const x2 = bar.x
            const yb = y2 + shift(bar)
            const tip = 'm-6,-4 l6,4 l-6,4'
            // Room for the elbow: straight through when the successor starts after the
            // predecessor ends, otherwise out, around through the gap between the rows, and
            // in; the elbows stay inside the canvas (a bar at the window's edge).
            const away = Math.min(x1 + ARROW_GAP, view.width)
            const back = Math.max(x2 - ARROW_GAP, 0)
            // Two pieces of one line (lineBy()) side by side: back through the gap under
            // their row, never along the bars themselves.
            const turn = y1 === yb ? from.bottom + shift(from.bar) - 2 : (y1 + yb) / 2
            const d = x2 - x1 >= ARROW_GAP * 2
                ? `M${x1},${y1} H${away} V${yb} H${x2} ${tip}`
                : `M${x1},${y1} H${away} V${turn} H${back} V${yb} H${x2} ${tip}`
            out.push({ key: `${dep}>${bar.id}`, d, late: bar.s < from.bar.e })
        }
    }

    return out
}

/**
 * Stack overlapping bars into lanes (greedy, by start); sets bar.lane and returns the
 * lane count. `gap` is the room kept between two bars of a lane: a line's pieces
 * (lineBy()) that touch end to start are one stretch of work, and share their lane.
 */
export function pack(bars, gap = 2) {
    const ends = []

    for (const bar of [...bars].sort((a, b) => a.x - b.x || a.w - b.w)) {
        const from = bar.milestone ? bar.x - MILESTONE_PX / 2 : bar.x
        const to = bar.milestone ? bar.x + MILESTONE_PX / 2 : bar.x + bar.w
        let lane = ends.findIndex((end) => end <= from)
        if (lane < 0) {
            lane = ends.length
            ends.push(0)
        }
        ends[lane] = to + gap
        bar.lane = lane
    }

    return ends.length
}

/* ------------------------------------------------------------------ Alpine component */

export default function packstubGantt(config) {
    // Alpine binds $root, $refs, $wire and $dispatch to the element an expression ran
    // on. A bar's key or pointer handler keeps running after a rebuild destroyed that
    // bar (a regroup in the compact layout, a carried bar), when those resolve to
    // nothing: init() keeps the chart's own here, and ui() hands them back.
    const dom = {}

    return {
        bars: config.bars,
        // lineBy(): the lines holding several pieces, each with all its pieces (in the window or not), by key.
        lineIndex: new Map((config.lines || []).map((line) => [String(line.key), line])),
        total: config.total ?? config.bars.length,
        groups: config.groups,
        // rowBy(): the rows in order, listed ones first, each { key, label, color, group }.
        rows: config.rows || [],
        period: config.window,
        range: { ...config.window },
        // A config rendered after the chart's first render carries no filters: they
        // come with the state the chart then asks for (see refresh()).
        filters: config.filters || [],
        needsFilters: config.filters === null,
        filtersLayout: config.filtersLayout || 'dropdown',
        // The toolbar's search box (searchable() attributes); refresh() sends the term with the filters.
        searchable: !! config.searchable,
        search: '',
        barActions: config.barActions || [],
        barAction: config.barAction,
        createAction: config.createAction,
        readOnly: config.readOnly,
        compact: config.compact,
        height: config.height,
        scales: config.scales || ['day', 'week', 'month', 'year'],
        t: config.i18n,
        scale: config.scale,
        // A factor on the level's pixels per day: Ctrl/⌘ + wheel, a pinch, or Fit
        zoom: 1,
        pinch: null,
        active: {},
        collapsed: {},
        view: { start: 0, days: 0, width: 0, pxPerDay: 1, columns: [], top: [], shades: [], todayX: null, rows: [] },
        slice: { from: 0, to: 0, top: 0, bottom: 0 },
        xrange: null,
        windowed: false,
        offsets: [],
        dragging: false,
        pointer: null,
        keyboard: null,
        // The group a dragged bar would land in, when it is not its own (its rows light up).
        target: null,
        // rowBy(): the row (its view key) a dragged bar would land on, when it is not its own.
        targetRow: null,
        swallowClick: false,
        pressed: null,
        autoScrolling: false,
        filling: false,
        userRange: false,
        pending: 0,
        flash: null,
        hint: null,
        live: '',
        menu: null,
        refreshSeq: 0,

        init() {
            for (const name of ['root', 'refs', 'wire', 'dispatch']) dom[name] = this['$' + name]
            this.active = Object.fromEntries(this.filters.map((f) => [f.name, this.blank(f)]))

            for (const group of this.groups) {
                this.collapsed[group.key] = !! group.collapsed
            }

            const fromUrl = this.restore()
            // Filament's fields (the window's pickers, the filters) show what the browser holds from the start.
            this.syncWindow()
            this.syncFields()

            // A config without a window has nothing to draw yet: the server's answer
            // (apply()) draws it, instead of a one-column, no-row chart for the round trip.
            if (this.period.start) {
                this.rebuild()
            }

            this.fit = () => {
                if (this.height) return
                const el = this.ui('refs').scroll
                if (! el) return
                const top = el.getBoundingClientRect().top + window.scrollY
                // the footer (the bar count) sits under the chart inside the container
                const footer = this.ui('root').querySelector?.('.ps-gantt-footer')?.offsetHeight || 0
                this.ui('root').style.setProperty('--ps-gantt-height', Math.max(320, window.innerHeight - top - footer - 24) + 'px')
            }
            window.addEventListener('resize', this.fit)
            this.$nextTick(() => {
                this.fit()
                this.scrollToToday(false, false)
                // A window the URL asks for is loaded first; it fills the width once there.
                if (! fromUrl.window) this.fill()
            })

            // The URL asked for another window or filters than the page rendered
            // with; or the page rendered no state at all (a chart that appeared after
            // the component's first render): the server is asked for it.
            if (fromUrl.window) {
                this.applyWindow()
            } else if (fromUrl.filters || ! this.period.start) {
                this.refresh()
            }

            if (config.poll) {
                this.poller = setInterval(() => {
                    if (! document.hidden && ! this.dragging && ! this.pending && ! this.menu && ! this.keyboard) {
                        this.refresh(true)
                    }
                }, config.poll)
            }
        },

        /** The chart's $root, $refs, $wire or $dispatch, whatever element the running expression came from. */
        ui(name) {
            return dom[name] ?? this['$' + name]
        },

        destroy() {
            window.removeEventListener('resize', this.fit)
            clearInterval(this.poller)
            clearTimeout(this.keyboard?.timer)
        },

        /* ------------------------------------------------------------ drawing */

        get unitDays() {
            return config.unit === 'hour' ? 1 / 24 : 1
        },

        /** What a bar snaps to, in ms: whole days for a whole-day record, whatever the chart's unit (it has no hours). */
        unitFor(bar) {
            return bar?.allDay ? DAY : this.unitDays * DAY
        },

        /**
         * The shortest and the longest a resize may make a bar, in ms (the server's
         * minDuration()/maxDuration()), rounded to whole units: a bound of 36 hours at
         * the day unit stops the handle at 2 days, never at 1.5, which the server would
         * snap to 1 and refuse.
         */
        limitsFor(bar) {
            const unit = this.unitFor(bar)
            const min = Math.ceil(Math.max(unit, (config.minMinutes || 0) * 60000) / unit) * unit
            const max = config.maxMinutes ? Math.max(min, Math.floor(config.maxMinutes * 60000 / unit) * unit) : Infinity

            return { min, max }
        },

        get limits() {
            return this.limitsFor(null)
        },

        /** The bars that are records: a parent's computed span (ParentSpan::FromChildren) is not one. */
        get barCount() {
            return this.bars.reduce((n, bar) => n + (bar.computed ? 0 : 1), 0)
        },

        get visibleRows() {
            return this.windowed ? this.view.rows.slice(this.slice.from, this.slice.to) : this.view.rows
        },

        // Hours over months are thousands of columns: only those in view (and a
        // screen either side) are in the DOM.
        inView(cells) {
            if (! this.xrange || this.view.columns.length <= COLUMNS_IN_DOM) return cells
            const { x0, x1 } = this.xrange

            return cells.filter((c) => c.x + c.w >= x0 && c.x <= x1)
        },

        get visibleColumns() {
            return this.inView(this.view.columns)
        },

        /** The dependency arrows, following a bar in the air (its x, or the row it is carried to). */
        get arrowPaths() {
            const p = this.pointer
            return arrows(this.view, p && p.dy ? { ids: [p.bar.id, ...(p.mates || []).map((m) => m.id)], dy: p.dy } : null)
        },

        /** Every arrow of one kind as a single path (the SVG holds two: on time, and late). */
        arrowD(late) {
            return this.arrowPaths.filter((a) => a.late === late).map((a) => a.d).join(' ')
        },

        get bodyHeight() {
            return this.view.rows.reduce((h, row) => h + row.height, 0)
        },

        get visibleTop() {
            return this.inView(this.view.top)
        },

        get visibleShades() {
            return this.inView(this.view.shades)
        },

        axis() {
            return {
                start: this.period.start,
                end: this.period.end,
                today: config.today,
                now: config.now,
                pxPerDay: (config.pxPerDay[this.scale] || 18) * this.zoom,
                rowHeight: config.rowHeight,
                firstDayOfWeek: config.firstDayOfWeek,
                weekendDays: config.weekendDays,
                locale: config.locale,
                groups: this.groups,
                grouped: config.grouped,
                compact: config.compact,
                lineLayout: this.lineDrags,
                lines: this.lineIndex,
                rowLayout: !! config.rowLayout,
                rows: this.rows,
                unassignedLabel: config.unassignedLabel ?? this.t.unassigned ?? '',
                unassignedRow: !! config.unassignedRow,
                collapsed: this.collapsed,
            }
        },

        rebuild() {
            this.view = layout(this.bars, this.axis(), this.scale)

            // A bar in the air (another bar's answer arrived mid-drag), or one being
            // nudged with the keys, keeps its new dates on the fresh row bar.
            const p = this.pointer
            if (p?.bar) {
                const fresh = this.rowBar(p.bar.id)
                if (fresh && fresh !== p.bar) {
                    fresh.s = p.bar.s
                    fresh.e = p.bar.e
                    this.place(fresh)
                    p.bar = fresh
                }
            }
            const k = this.keyboard
            if (k) {
                const fresh = this.rowBar(k.id)
                if (fresh) {
                    fresh.s = k.s
                    fresh.e = k.e
                    this.place(fresh)
                }
            }
            // The other pieces of a line in the air keep their shift too.
            if (p?.mates?.length) this.shiftMates(p, p.shift || 0)
            if (k?.mates?.length) this.shiftMates(k, k.s - k.s0)

            // Row windowing above a few hundred rows: only the rows in view are in the DOM.
            this.windowed = this.view.rows.length > 200
            this.offsets = []
            let y = 0
            for (const row of this.view.rows) {
                this.offsets.push(y)
                y += row.height
            }
            this.xrange = null
            this.onScroll()
        },

        onScroll() {
            this.onScrollX()

            if (! this.windowed) {
                this.slice = { from: 0, to: this.view.rows.length, top: 0, bottom: 0 }
                return
            }

            const el = this.ui('refs').scroll
            const total = this.offsets.length ? this.offsets[this.offsets.length - 1] + this.view.rows[this.offsets.length - 1].height : 0
            const overscan = 400
            const y0 = Math.max(0, (el?.scrollTop || 0) - overscan)
            const y1 = (el?.scrollTop || 0) + (el?.clientHeight || 600) + overscan

            let from = 0
            let to = this.offsets.length
            let lo = 0
            let hi = this.offsets.length - 1
            while (lo < hi) {
                const mid = (lo + hi) >> 1
                if (this.offsets[mid] + this.view.rows[mid].height < y0) lo = mid + 1
                else hi = mid
            }
            from = lo
            while (to > from && this.offsets[to - 1] > y1) to--

            this.slice = { from, to, top: this.offsets[from] || 0, bottom: total - (to < this.offsets.length ? this.offsets[to] : total) }
        },

        /** The horizontal slice of columns in the DOM, moved only once the view nears its edge. */
        onScrollX() {
            if (this.view.columns.length <= COLUMNS_IN_DOM) {
                if (this.xrange) this.xrange = null
                return
            }

            const el = this.ui('refs').scroll
            const left = Math.abs(el?.scrollLeft || 0)
            const width = el?.clientWidth || 1600
            const r = this.xrange

            if (r && left - width / 2 >= r.x0 && left + width * 1.5 <= r.x1) return

            this.xrange = { x0: Math.max(0, left - width), x1: left + width * 2 }
        },

        /** The row bar (the laid-out copy) with this id, if it is on the chart. */
        rowBar(id) {
            for (const row of this.view.rows) {
                for (const bar of row.bars) {
                    if (bar.id === id) return bar
                }
            }
            return null
        },

        /* ------------------------------------------------------------ lines (lineBy()) */

        setLines(lines) {
            this.lineIndex = new Map((lines || []).map((line) => [String(line.key), line]))
        },

        /** Whether drags work on lines: the rows are lines (lineBy() outside the compact layout). */
        get lineDrags() {
            return !! config.lineLayout && ! config.compact
        },

        /** A bar's line when it holds other pieces too, with all of them; null otherwise. */
        lineOf(bar) {
            if (bar?.line === null || bar?.line === undefined || bar.line === '') return null
            return this.lineIndex.get(String(bar.line)) ?? null
        },

        /** The id of a line's first piece: the earliest, its own record first among those starting together. */
        firstPiece(line) {
            // The server's order (Gantt::inLineOrder()): by start, the line's own record, then the key
            // (numbers as numbers, as the database orders an integer key).
            const root = String(line.key)
            let first = null
            for (const piece of line.pieces) {
                const c = { id: String(piece.id), s: parse(piece.start) }
                if (first === null || c.s < first.s || (c.s === first.s && first.id !== root
                    && (c.id === root || c.id.localeCompare(first.id, undefined, { numeric: true }) < 0))) first = c
            }
            return first?.id ?? null
        },

        /** Whether dragging this bar (not an edge) moves its whole line: it is the line's first piece, unless movePiecesAlone(). */
        carriesLine(bar) {
            const line = this.lineDrags ? this.lineOf(bar) : null
            return !! line && ! config.movePiecesAlone && this.firstPiece(line) === String(bar.id)
        },

        lineMovable(line) {
            return line.pieces.every((piece) => piece.movable)
        },

        lineRegroupable(line) {
            return line.pieces.every((piece) => piece.regroupable)
        },

        /** What a line moves by, in ms: whole days as soon as one piece is whole days (as the server snaps it). */
        lineUnit(key) {
            return this.lineIndex.get(key)?.pieces.some((piece) => piece.allDay) ? DAY : this.unitDays * DAY
        },

        /**
         * How far a piece may go before it meets another piece of its line, with
         * preventLineOverlap(): { left, right } in ms, or null when it already overlaps
         * one (data from outside the chart: the server decides).
         */
        walls(line, bar) {
            return this.wallsOf(line.pieces, bar)
        },

        /** walls() among any bars ({ id, start, end, milestone }, as the server sends them). */
        wallsOf(pieces, bar) {
            let left = -Infinity
            let right = Infinity
            for (const piece of pieces) {
                if (String(piece.id) === String(bar.id)) continue
                const s = parse(piece.start)
                const e = piece.milestone ? s : parse(piece.end)
                if (e <= bar.s) left = Math.max(left, e)
                else if (s >= bar.e) right = Math.min(right, s)
                else return null
            }
            // In whole units from the bar's edges: a neighbour off the grid (9 Oct 12:00 at
            // the day unit) stops the bar a whole unit short, never at a half the server
            // would round (half up) into that neighbour and refuse.
            const unit = this.unitFor(bar)
            return {
                left: left === -Infinity ? left : bar.s + Math.ceil((left - bar.s) / unit) * unit,
                right: right === Infinity ? right : bar.e + Math.floor((right - bar.e) / unit) * unit,
            }
        },

        /**
         * rowBy() with preventRowOverlap(): how far a bar may go before it meets another
         * bar of its row (any group: the row is the resource), as walls() says; null
         * without the rule or without a row. Only the window's bars: the server checks the rest.
         */
        rowWalls(bar) {
            if (! config.rowLayout || ! config.preventRowOverlap || ! bar.row || bar.parent) return null
            // A parent's span is never in the way of its children.
            return this.wallsOf(this.bars.filter((b) => ! b.parent && String(b.row ?? '') === String(bar.row)), bar)
        },

        /**
         * rowBy() with preventRowOverlap(): whether [s, e) on a row meets another bar of
         * it (bars that touch do not; `id` is the bar asking). The window's bars, as the
         * server sent them: it checks the rest.
         */
        rowTaken(row, id, s, e) {
            if (! config.rowLayout || ! config.preventRowOverlap || ! row) return false
            return this.bars.some((b) => {
                if (b.parent || String(b.row ?? '') !== String(row) || (id !== null && String(b.id) === String(id))) return false
                const from = parse(b.start)
                const until = b.milestone ? from : parse(b.end)
                return from < e && s < until
            })
        },

        /**
         * Everything that stops a bar along its row: the next piece of its line
         * (preventLineOverlap(), unless it carries the line), the next bar of its row
         * (preventRowOverlap(); not while it carries its line: the pieces that follow it
         * are on that row too, the server checks the others) and its parent's dates.
         */
        barWalls(entry, bar, together) {
            const line = config.preventLineOverlap && entry && ! together ? this.walls(entry, bar) : null
            const row = together ? null : this.rowWalls(bar)
            const both = line && row ? { left: Math.max(line.left, row.left), right: Math.min(line.right, row.right) } : (line ?? row)
            return this.wallsFor(bar, both)
        },

        /**
         * keepInsideParent(): the dates of its parent a child has to stay within, as
         * walls ({ left, right } in ms): only while the parent's span is on the chart and
         * holds the bar now; null otherwise (the server decides).
         */
        parentWalls(bar) {
            if (! config.keepInsideParent || bar.parent || ! bar.row) return null
            const parent = this.bars.find((b) => b.parent && String(b.id) === String(bar.row))
            if (! parent) return null
            const left = parse(parent.start)
            const right = parent.milestone ? left : parse(parent.end)
            return bar.s >= left && bar.e <= right ? { left, right } : null
        },

        /** A bar's walls: the next piece of its line or bar of its row (`base`), and its parent's dates, the nearer of each. */
        wallsFor(bar, base) {
            const inside = this.parentWalls(bar)
            if (! inside) return base
            return { left: Math.max(base?.left ?? -Infinity, inside.left), right: Math.min(base?.right ?? Infinity, inside.right) }
        },

        /**
         * keepInsideParent(): what a parent's span has to keep holding when it is resized
         * or moved alone: its children on the chart, first start to last end ({ s, e } in
         * ms); null without any, or when one is outside already (the server decides).
         */
        childHold(bar) {
            if (! config.keepInsideParent || ! bar.parent) return null
            let s = Infinity
            let e = -Infinity
            for (const b of this.bars) {
                if (b.parent || String(b.row ?? '') !== String(bar.id)) continue
                const from = parse(b.start)
                s = Math.min(s, from)
                e = Math.max(e, b.milestone ? from : parse(b.end))
            }
            return s !== Infinity && bar.s <= s && bar.e >= e ? { s, e } : null
        },

        /** A row's name (rowBy()), for the hints; the unassigned row's for no row. */
        rowLabel(key) {
            if (! key) return config.unassignedLabel ?? this.t.unassigned ?? ''
            return this.rows.find((r) => String(r.key) === String(key))?.label ?? String(key)
        },

        /** Where a drag or the keys would put a bar, when it is not where it was: ' · group · row'. */
        placeHint(state) {
            const parts = []
            if (state.g !== state.g0) parts.push(this.groupLabel(state.g))
            if (config.rowLayout && state.r !== state.r0) parts.push(this.rowLabel(state.r))
            const text = parts.filter(Boolean).join(' · ')
            return text ? ' · ' + text : ''
        },

        /** The dot before a row's name: its group's colour on a group's row, its own on a row of rowBy(). */
        labelColor(row) {
            if (row.type === 'row') return row.color || null
            return row.type === 'bar' ? null : (row.group.color || null)
        },

        /**
         * rowBy(): every row a bar may land on with ↑/↓, in the chart's order, folded
         * groups included (a fold opens when the bar gets there); only its own group's
         * when the group cannot be written. The rows as they were when the keys began:
         * a row that only this bar was in is still there to come back to.
         */
        rowTargets(k) {
            const bars = this.bars.map((b) => (b.id === k.id || (k.line && String(b.line) === k.line)
                ? { ...b, group: k.groups0?.get(b.id) ?? k.g0, row: k.rows0?.get(b.id) ?? k.r0 }
                : b))
            const view = layout(bars, { ...this.axis(), collapsed: {} }, this.scale)
            const out = []
            for (const row of view.rows) {
                if (row.type === 'group') continue
                if (! config.groupMoves && String(row.group.key) !== k.g0) continue
                out.push({ g: String(row.group.key), r: String(row.row ?? '') })
            }
            return out
        },

        /** The other pieces of the line on the dragged bar's row, where they were: they follow when the line moves. */
        matesOf(bar) {
            const row = this.view.rows.find((r) => r.bars.includes(bar))
            return (row?.bars || []).filter((b) => b !== bar && String(b.line) === String(bar.line)).map((b) => ({ id: b.id, s: b.s, e: b.e }))
        },

        /** The bars under a parent's span, on the chart: they follow when the span is dragged (moveChildrenWithParent()). */
        childrenOf(bar) {
            return this.view.rows.flatMap((row) => row.bars.filter((b) => ! b.parent && String(b.row ?? '') === String(bar.id)))
        },

        /** Whether a whole drag of this bar takes others along: a parent's span, with its children. */
        carriesChildren(bar) {
            return !! bar.parent && !! config.moveChildren
        },

        /** Shift the mates of a line (or the children of a parent) in the air (a drag's or the keys') by d ms from where they were. */
        shiftMates(state, d) {
            state.shift = d
            for (const mate of state.mates) {
                const b = this.rowBar(mate.id)
                if (! b) continue
                b.s = mate.s + d
                b.e = mate.e + d
                this.place(b)
            }
        },

        /** Whether a bar is drawn over another row while the pointer carries it (or its line) there. */
        carried(bar) {
            const p = this.pointer
            return !! (p?.dy && (p.bar.id === bar.id || p.mates?.some((m) => m.id === bar.id)))
        },

        /**
         * The dotted connectors between the pieces of a line, where its work stops and
         * resumes; one starts after a label drawn outside its piece, never through it.
         */
        gaps(row) {
            const reach = (bar) => bar.x + bar.w + (this.labelOutside(bar, row) ? this.textWidth(bar.label) + 12 : 0)
            const chain = (bars, line = null) => {
                const sorted = [...bars].sort((a, b) => a.x - b.x)
                const out = []
                let end = reach(sorted[0])
                let lane = sorted[0].lane || 0
                for (const bar of sorted.slice(1)) {
                    // On a row of rowBy(), only between pieces that share a lane.
                    if (bar.x > end && (line === null || (bar.lane || 0) === lane)) out.push(line === null ? { key: 'gap:' + bar.id, x: end, w: bar.x - end } : { key: 'gap:' + bar.id, x: end, w: bar.x - end, lane, line })
                    end = Math.max(end, reach(bar))
                    lane = bar.lane || 0
                }
                return out
            }

            // rowBy() with lineBy(): one chain per line that has several pieces on this row.
            if (row.type === 'row') {
                if (! this.lineDrags || row.bars.length < 2) return []
                const lines = new Map()
                for (const bar of row.bars) {
                    if (bar.parent || bar.line === null || bar.line === undefined || bar.line === '') continue
                    const key = String(bar.line)
                    if (! lines.has(key)) lines.set(key, [])
                    lines.get(key).push(bar)
                }
                return [...lines].flatMap(([key, bars]) => (bars.length > 1 ? chain(bars, key) : []))
            }

            if (! row.line || row.bars.length < 2) return []
            return chain(row.bars)
        },

        gapStyle(gap, row) {
            const style = { 'inset-inline-start': gap.x + 'px', width: gap.w + 'px', top: (gap.lane || 0) * config.rowHeight + config.rowHeight / 2 + 'px' }
            const p = this.pointer
            // Carried to another row with its line: only that line's joiners follow.
            if (p?.dy && p.mates && row.bars.some((b) => b.id === p.bar.id) && (gap.line === null || gap.line === undefined || String(p.bar.line) === gap.line)) style.transform = `translateY(${p.dy}px)`
            return style
        },

        /** A moved bar's new dates and row on its line's piece (what the walls and the first piece are read from). */
        syncPiece(bar) {
            const piece = this.lineOf(bar)?.pieces.find((p) => String(p.id) === String(bar.id))
            if (! piece) return
            piece.start = bar.start
            piece.end = bar.end
            piece.group = bar.group
            if (bar.row !== undefined) piece.row = bar.row
            piece.movable = !! bar.movable
            piece.regroupable = !! bar.regroupable
        },

        /** Pixels from a row bar's dates. */
        place(bar) {
            bar.x = ((bar.s - this.view.start) / DAY) * this.view.pxPerDay
            bar.w = bar.milestone ? 0 : Math.max(((bar.e - bar.s) / DAY) * this.view.pxPerDay, 4)
        },

        cell(c) {
            return { 'inset-inline-start': c.x + 'px', width: c.w + 'px' }
        },

        /** The folded group's extent line: where it sits, in the group's colour when it has one. */
        spanStyle(row) {
            const span = row.span
            if (! span) return {}

            const style = { 'inset-inline-start': span.x + 'px', width: span.w + 'px' }

            if (row.group.color) style['--ps-bar'] = this.tone(row.group.color)

            return style
        },

        /** The dates a folded group's line stands for, as a bar's tooltip would say them. */
        spanTitle(row) {
            return row.span ? this.dates(row.span) : ''
        },

        // An object, never a string: Alpine sets each property on its own, so a value
        // (a colour from user data) can never add declarations of its own.
        barStyle(bar) {
            // A parent's span fills its row, whatever lanes its children take.
            const top = bar.parent ? 2 : 4 + bar.lane * config.rowHeight
            const inner = bar.parent ? (bar.lanes || 1) * config.rowHeight - 4 : config.rowHeight - 8
            const style = { 'inset-inline-start': bar.x + 'px', top: top + 'px', height: inner + 'px', '--ps-bar': this.tone(bar.color) }

            // Carried to another row (with its line): drawn over that row while the pointer is down.
            if (this.carried(bar)) {
                style.transform = `translateY(${this.pointer.dy}px)`
            }

            // The diamond centres itself on the row's inner height, whatever row_height is.
            if (bar.milestone && ! bar.parent) {
                style['--ps-gantt-row-inner'] = inner + 'px'
            } else {
                style.width = bar.w + 'px'
            }

            return style
        },

        /** Whether a bar is too narrow for resize handles: a drag anywhere on it moves it. */
        narrow(bar) {
            return ! bar.milestone && bar.w < HANDLES_MIN_PX
        },

        /**
         * Whether a bar's label is drawn after it, because it does not fit inside. Not
         * in the compact layout: the next bar of the lane may sit right there. On a
         * line's row, only when it fits before the next piece of its lane.
         */
        labelOutside(bar, row = null) {
            // A parent's span has its name on the row.
            if (bar.milestone || this.compact || ! bar.label || bar.parent) return false
            if (this.textWidth(bar.label) + 18 <= bar.w) return false
            if (! row || row.bars.length < 2) return true

            const end = bar.x + bar.w
            let room = Infinity
            for (const other of row.bars) {
                if (other !== bar && ! other.parent && (other.lane || 0) === (bar.lane || 0) && other.x >= end) room = Math.min(room, other.x - end)
            }

            return this.textWidth(bar.label) + 12 <= room
        },

        textWidth(text) {
            this.widths ??= new Map()
            if (this.widths.has(text)) return this.widths.get(text)

            let width = String(text).length * 6.6
            try {
                this.ruler ??= typeof document.createElement === 'function' ? document.createElement('canvas').getContext('2d') : null
                if (this.ruler) {
                    this.ruler.font = `500 12px ${getComputedStyle(this.ui('root')).fontFamily || 'sans-serif'}`
                    width = this.ruler.measureText(String(text)).width
                }
            } catch (e) {}

            this.widths.set(text, width)

            return width
        },

        // A whole-day bar reads as dates (its end day is the day before the chart's
        // end, so a one-day bar is one date); a bar with times reads as moments. The
        // record decides, not the snap unit.
        tooltip(bar) {
            const lines = [bar.label, this.dates(bar)]

            if (bar.milestone) lines.push(this.t.milestone)
            if (bar.progress !== null && bar.progress !== undefined && ! bar.milestone) lines.push(this.t.progress.replace(':percent', bar.progress))
            if (bar.tooltip) lines.push(bar.tooltip)

            return lines.filter(Boolean).join('\n')
        },

        dates(bar) {
            const allDay = !! bar.allDay
            const pattern = allDay ? config.dateFormat : config.dateTimeFormat

            if (bar.milestone || (allDay && bar.e - bar.s === DAY)) return phpDate(bar.s, pattern, config.locale)

            const end = allDay ? bar.e - DAY : bar.e
            // A range within one day says the date once: "Oct 9, 2026 08:00 – 12:00" (the time part of the format, when it ends with one).
            const time = ! allDay && phpDate(bar.s, 'Y-m-d') === phpDate(end, 'Y-m-d') ? timePattern(pattern) : null

            return `${phpDate(bar.s, pattern, config.locale)} – ${phpDate(end, time || pattern, config.locale)}`
        },

        /** The bar's accessible name: what it is, then how to work it from the keyboard. */
        describe(bar) {
            const name = this.tooltip(bar)

            return ! this.readOnly && (bar.movable || bar.resizable) && this.t.keyboard ? `${name}\n${this.t.keyboard}` : name
        },

        countText() {
            const n = this.barCount

            if (this.total > n) {
                return this.t.truncated.replace(':count', n).replace(':total', this.total)
            }

            return this.plural(this.t.bars, n).replace(':count', n)
        },

        /** A translation that is a string, or plural forms by Intl.PluralRules category ({ one, few, other }). */
        plural(forms, n) {
            if (typeof forms === 'string') return forms
            try {
                return forms[new Intl.PluralRules(config.locale).select(n)] ?? forms.other
            } catch (e) {
                return forms.other
            }
        },

        setScale(scale) {
            if (! config.pxPerDay[scale] || ! this.scales.includes(scale)) return
            // A level chosen by hand is shown as designed.
            this.zoom = 1
            const el = this.ui('refs').scroll
            // scrollLeft counts from the start edge and is negative in RTL: work on its size, keep its sign.
            const dir = this.rtl() ? -1 : 1
            // The day in the middle of the chart's visible part (after the sticky label column) stays there.
            const half = el ? (el.clientWidth - this.labelWidth()) / 2 : 0
            const centre = el ? (Math.abs(el.scrollLeft) + half) / (this.view.pxPerDay || 1) : null
            this.scale = scale
            this.rebuild()
            this.persist()
            if (el && centre !== null) {
                this.$nextTick(() => {
                    el.scrollLeft = dir * Math.max(0, centre * this.view.pxPerDay - half)
                    this.onScroll()
                    this.fill()
                })
            }
        },

        rtl() {
            return getComputedStyle(this.ui('root')).direction === 'rtl'
        },

        /* ------------------------------------------------------------ zoom */

        /**
         * Draw the chart at this many pixels per day, keeping the day under `clientX`
         * where it is (the window's start when there is none): the nearest level's
         * factor, and the next finer or coarser level when the factor leaves its
         * range. Levels not offered by the chart are skipped.
         */
        zoomTo(ppd, clientX = null) {
            const el = this.ui('refs').scroll
            const rect = el?.getBoundingClientRect?.()
            const label = this.labelWidth()
            const xIn = rect && clientX !== null
                ? Math.max(0, this.rtl() ? rect.right - label - clientX : clientX - rect.left - label)
                : 0
            const day = el ? (Math.abs(el.scrollLeft || 0) + xIn) / (this.view.pxPerDay || 1) : 0

            const base = (s) => config.pxPerDay[s] || 18
            let scale = this.scale
            let zoom = ppd / base(scale)
            // Finer levels come first in the list: step while the factor is out of range,
            // in one direction only. Two levels further apart than the range covers (hour
            // at 960 a day, day at 48) still meet: the step lands at the new level's edge.
            if (zoom > ZOOM_MAX) {
                while (zoom > ZOOM_MAX && this.scales.indexOf(scale) > 0) {
                    scale = this.scales[this.scales.indexOf(scale) - 1]
                    zoom = ppd / base(scale)
                }
            } else {
                while (zoom < ZOOM_MIN && this.scales.indexOf(scale) < this.scales.length - 1) {
                    scale = this.scales[this.scales.indexOf(scale) + 1]
                    zoom = ppd / base(scale)
                }
            }
            this.scale = scale
            this.zoom = Math.min(ZOOM_MAX, Math.max(ZOOM_MIN, zoom))
            // A zoom asks for another view of the dates: a range typed earlier no longer
            // pins the window, so zooming out fills the width again (fill()).
            this.userRange = false
            this.rebuild()
            this.persist()

            if (el) {
                this.$nextTick(() => {
                    el.scrollLeft = (this.rtl() ? -1 : 1) * Math.max(0, day * this.view.pxPerDay - xIn)
                    this.onScroll()
                    this.fill()
                })
            }
        },

        /** Ctrl/⌘ + wheel zooms around the pointer; a plain wheel scrolls as usual. */
        onWheel(event) {
            if (! (event.ctrlKey || event.metaKey)) return
            event.preventDefault()
            this.zoomTo(this.view.pxPerDay * Math.exp(-event.deltaY * 0.0015), event.clientX)
        },

        pinchStart(event) {
            if (event.touches?.length !== 2) return
            const [a, b] = event.touches
            this.pinch = { d0: Math.hypot(a.clientX - b.clientX, a.clientY - b.clientY), ppd0: this.view.pxPerDay }
        },

        pinchMove(event) {
            const p = this.pinch
            if (! p || event.touches?.length !== 2) return
            if (event.cancelable) event.preventDefault()
            const [a, b] = event.touches
            const d = Math.hypot(a.clientX - b.clientX, a.clientY - b.clientY)
            this.zoomTo(p.ppd0 * d / (p.d0 || 1), (a.clientX + b.clientX) / 2)
        },

        pinchEnd() {
            this.pinch = null
        },

        /**
         * Everything the filters leave, in one screen: the span of those bars is
         * loaded (the server caps it like any window, and says so), then drawn at the
         * pixels per day that fill the chart's width with every label beside a bar
         * still inside it; the window then grows to the width (fill()).
         */
        fitAll() {
            return this.ui('wire').ganttSpan(this.activeFilters(), this.search).then((span) => {
                if (! span?.start) return
                this.range = { start: span.start, end: span.end }

                return this.applyWindow(() => {
                    const el = this.ui('refs').scroll
                    const room = Math.max(120, (el?.clientWidth || 1200) - this.labelWidth())
                    // from the window's start: the zoom anchors on the day under the pointer, here the first
                    if (el) el.scrollLeft = 0
                    // Loaded as a typed range so the cut is announced; from here the window may grow to the width.
                    this.userRange = false
                    this.zoomTo(this.fitPxPerDay(room))
                }, { user: true, keepScroll: true })
            })
        },

        /**
         * The pixels per day at which the loaded window fills `room`, lowered until every
         * label drawn beside its bar (a milestone's, a bar's too narrow for it) ends
         * inside: labels keep their width while the bars shrink, so a few passes settle it.
         */
        fitPxPerDay(room) {
            const bars = this.view.rows.flatMap((row) => row.bars)
            let ppd = room / Math.max(1, this.view.days)

            for (let pass = 0; pass < 4; pass++) {
                let next = ppd
                for (const bar of bars) {
                    if (! bar.label) continue
                    const labelPx = this.textWidth(bar.label) + 18
                    const outside = bar.milestone || (! this.compact && ((bar.e - bar.s) / DAY) * ppd < labelPx)
                    if (! outside) continue
                    const endDay = Math.max(0.5, (bar.e - this.view.start) / DAY)
                    const limit = (room - labelPx) / endDay
                    if (limit > 0 && limit < next) next = limit
                }
                if (Math.abs(next - ppd) < 0.01) break
                ppd = next
            }

            return ppd
        },

        /** Width of the sticky label column, in px. */
        labelWidth() {
            return this.ui('refs').scroll?.querySelector?.('.ps-gantt-corner')?.offsetWidth || 0
        },

        /**
         * Today into view. Outside the window, the window moves to today first (unless
         * `load` is false: the first draw never changes the window by itself).
         */
        scrollToToday(smooth = true, load = true) {
            const el = this.ui('refs').scroll

            if (this.view.todayX === null) {
                if (! load || ! this.period.start) return
                const today = parse(config.today)
                const days = Math.max(this.view.days || 28, 7)
                const before = Math.min(7, Math.floor(days / 4))
                this.range = { start: format(today - before * DAY, false), end: format(today + (days - before - 1) * DAY, false) }

                return this.applyWindow(() => this.scrollToToday(false, false))
            }

            if (! el) return
            const left = Math.max(0, this.view.todayX - el.clientWidth / 3)
            el.scrollTo?.({ left: this.rtl() ? -left : left, behavior: smooth ? 'smooth' : 'auto' })
        },

        /**
         * A window narrower than the chart (zoomed out, or a wide screen) grows to fill
         * it: the grid and the bars reach the chart's end instead of stopping a third
         * of the way.
         */
        fill() {
            const el = this.ui('refs').scroll
            // A range the user typed is theirs: it is shown as asked, not widened.
            if (! el || ! this.period.start || this.filling || this.userRange || ! this.view.pxPerDay) return
            const room = (el.clientWidth || 0) - this.labelWidth()
            const need = Math.ceil(room / this.view.pxPerDay)
            // Once per width and zoom: a server that caps the window is not asked again.
            if (need <= this.view.days || this.fillTried === `${this.scale}:${need}`) return
            this.fillTried = `${this.scale}:${need}`

            this.range = { start: this.period.start, end: format(parse(this.period.start) + (need - 1) * DAY, false) }
            this.filling = true
            this.applyWindow(() => { this.filling = false }, { keepScroll: true })
        },

        toggle(key) {
            this.collapsed[key] = ! this.collapsed[key]
            this.rebuild()
            this.persist()
        },

        /* ------------------------------------------------------------ moving and resizing */

        /** Whether an edge of the bar may be dragged: a bar that started before the lock keeps its start. */
        resizes(bar, edge) {
            return !! bar.resizable && ! (edge === 'start' && bar.startFixed)
        },

        down(event, bar) {
            // One pointer at a time: a second finger neither starts a drag nor ends the first.
            if (event.button !== 0 || bar.src._pending || this.pointer) return
            this.flushNudge()
            // The keys just sent may have moved this bar (a nudged line takes all its pieces): wait for the answer.
            if (bar.src._pending) return
            // A bar too narrow for its handles is moved, whatever part of it was grabbed.
            const edge = this.narrow(bar) ? null : (event.target.dataset?.edge || null)
            // lineBy(): the first piece of a line carries the line, and a drag to another
            // group carries it whichever piece was grabbed, so every piece must agree.
            const entry = this.lineOf(bar)
            // A parent's span dragged whole takes its children along: every one on the chart has to be free to go.
            // It moves as a parent even when it is a piece of a line too: its children follow, the line's other pieces stay.
            const family = ! edge && this.carriesChildren(bar)
            const kids = family ? this.childrenOf(bar) : []
            const line = this.lineDrags && ! edge && ! family ? entry : null
            const together = !! line && this.carriesLine(bar)
            // A whole bar on a grouped chart (or one with rowBy()) may be carried to another row, even one whose dates are fixed.
            const rows = ! edge && (!! config.grouped || !! config.rowLayout) && (line ? this.lineRegroupable(line) : !! bar.regroupable)
            const allowed = edge ? this.resizes(bar, edge) : ((together ? this.lineMovable(line) : family ? bar.movable && kids.every((b) => b.movable && ! b.src._pending) : bar.movable) || rows)
            const touch = event.pointerType === 'touch' || event.pointerType === 'pen'

            this.menu = null
            let p = {
                id: event.pointerId, bar, edge, allowed, touch, picked: false, moved: false, press: null,
                x0: event.clientX, y0: event.clientY, cx: event.clientX, cy: event.clientY,
                s: bar.s, e: bar.e, sl0: this.ui('refs').scroll?.scrollLeft || 0,
                rows, g0: String(bar.group ?? ''), g: String(bar.group ?? ''), r0: String(bar.row ?? ''), r: String(bar.row ?? ''), dy: 0,
                // preventRowOverlap(): over another row, on a bar of it (the row turns red, a release there sends nothing).
                blocked: false,
                dir: this.rtl() ? -1 : 1, el: event.currentTarget,
                line: line ? String(line.key) : null, together, lineMoves: !! line && this.lineMovable(line),
                family,
                mates: line ? this.matesOf(bar) : kids.map((b) => ({ id: b.id, s: b.s, e: b.e })), shift: 0,
                // preventLineOverlap(): a piece moved or resized alone stops at the next piece of its line;
                // preventRowOverlap(): a bar at the next bar of its row.
                // keepInsideParent(): a child inside its parent's dates, a parent (alone) around its children.
                walls: this.barWalls(entry, bar, together),
                hold: family ? null : this.childHold(bar),
            }
            // From here on the reactive copy: it is what this.pointer returns (an
            // identity check against the plain object would never match), and a
            // change to it (picked) reaches the bar's classes.
            this.pointer = p
            p = this.pointer
            event.currentTarget.setPointerCapture?.(event.pointerId)

            // On touch a swipe scrolls the chart: a bar is picked up by holding it
            // still. Lifted without moving, the held bar opens its menu.
            if (touch) {
                p.press = setTimeout(() => {
                    if (this.pointer !== p || p.moved) return
                    p.picked = true
                    this.dragging = true
                    window.navigator?.vibrate?.(10)
                }, LONG_PRESS_MS)
            }
        },

        drag(event) {
            const p = this.pointer
            if (! p || event.pointerId !== p.id) return

            p.cx = event.clientX
            p.cy = event.clientY
            this.track(p)
        },

        /** The bar under the pointer: the pointer's travel plus how far the chart has scrolled since the press. */
        track(p) {
            if (p.select) return this.trackSelection(p)

            const sx = p.cx - p.x0
            const sy = p.cy - p.y0

            // A finger that moves before it holds is scrolling the chart: let it.
            if (p.touch && ! p.picked) {
                if (Math.hypot(sx, sy) > 8) this.release(p)
                return
            }

            // Either way: a drag that goes up or down is a drag too, never a click.
            if (! p.moved && Math.hypot(sx, sy) < 3) return

            // Past the threshold it is a drag, allowed or not: the release is never a click.
            p.moved = true
            clearTimeout(p.press)
            if (! p.allowed) return

            this.dragging = true
            const bar = p.bar

            // The row first: over another group, a line's piece carries its whole line.
            if (p.rows && config.rowLayout) {
                // rowBy(): any row but its own, in another group only when the group may be written.
                const at = this.rowAt(p.cy)
                const own = this.view.rows.findIndex((row) => row.bars.includes(bar))
                const row = at !== null ? this.view.rows[at] : null
                const g = row ? String(row.group.key) : p.g0
                const r = row ? String(row.row ?? '') : p.r0
                const other = !! row && row.type !== 'group' && (g === p.g0 || !! config.groupMoves) && (g !== p.g0 || r !== p.r0)
                this.targetRow = other ? row.key : null
                p.g = other ? g : p.g0
                p.r = other ? r : p.r0
                p.dy = other && own >= 0 ? this.offsets[at] - this.offsets[own] : 0
            } else if (p.rows) {
                const at = this.rowAt(p.cy)
                const own = this.view.rows.findIndex((row) => row.bars.includes(bar))
                const other = at !== null && this.view.rows[at].group.key !== p.g0
                this.target = other ? this.view.rows[at].group.key : null
                p.g = other ? this.target : p.g0
                p.dy = other && own >= 0 ? this.offsets[at] - this.offsets[own] : 0
            }

            // With rowBy(), a piece carried to another row carries its line there too.
            const carried = !! p.line && (p.together || p.g !== p.g0 || p.r !== p.r0)
            const scrolled = (this.ui('refs').scroll?.scrollLeft || 0) - p.sl0
            const dx = (sx + scrolled) * p.dir
            const unit = carried ? this.lineUnit(p.line) : this.unitFor(bar)
            const { min, max } = this.limitsFor(bar)
            // A whole bar (or line) with fixed dates only changes rows; an edge moves whenever it was allowed.
            const moves = p.edge ? true : carried ? p.lineMoves : bar.movable
            let delta = moves ? Math.round(dx / this.view.pxPerDay * DAY / unit) * unit : 0
            // On another row, the walls of the row it left are not its own (the server checks the new one).
            const walls = carried || p.r !== p.r0 ? null : p.walls

            if (p.edge === 'start') {
                bar.s = Math.max(Math.min(p.s + delta, p.e - min), p.e - max, walls?.left ?? -Infinity)
                if (p.hold) bar.s = Math.min(bar.s, p.hold.s)
                bar.e = p.e
            } else if (p.edge === 'end') {
                bar.s = p.s
                bar.e = Math.min(Math.max(p.e + delta, p.s + min), p.s + max, walls?.right ?? Infinity)
                if (p.hold) bar.e = Math.max(bar.e, p.hold.e)
            } else {
                if (walls) delta = Math.min(Math.max(delta, walls.left - p.s), walls.right - p.e)
                if (p.hold) delta = Math.min(Math.max(delta, p.hold.e - p.e), p.hold.s - p.s)
                bar.s = p.s + delta
                bar.e = p.e + delta
            }

            this.place(bar)
            if (p.mates.length) this.shiftMates(p, carried || p.family ? bar.s - p.s : 0)

            // Its own row has walls; another row says so while the bar is over one of its bars.
            p.blocked = p.r !== p.r0 && this.rowTaken(p.r, bar.id, bar.s, bar.e)
            this.hint = p.blocked ? (this.t.row_overlaps || '') : this.dates(bar) + this.placeHint(p)
            this.autoScroll(p)
        },

        /** The index of the row under a point on screen, or null outside the rows. */
        rowAt(clientY) {
            const body = this.ui('refs').body
            if (! body?.getBoundingClientRect || ! this.offsets.length) return null
            const y = clientY - body.getBoundingClientRect().top
            if (y < 0) return null
            for (let i = this.offsets.length - 1; i >= 0; i--) {
                if (y >= this.offsets[i]) return y < this.offsets[i] + this.view.rows[i].height ? i : null
            }
            return null
        },

        groupLabel(key) {
            return this.groups.find((g) => String(g.key) === String(key))?.label ?? (key || this.t.ungrouped || '')
        },

        /** While a bar is dragged near the chart's start or end edge, the chart scrolls, faster the closer it gets. */
        autoScroll(p) {
            const el = this.ui('refs').scroll
            if (this.autoScrolling || ! el || typeof requestAnimationFrame !== 'function') return
            this.autoScrolling = true

            const step = () => {
                if (this.pointer !== p || ! p.allowed) {
                    this.autoScrolling = false
                    return
                }

                const r = el.getBoundingClientRect()
                const label = this.labelWidth()
                const left = r.left + (this.rtl() ? 0 : label)
                const right = r.right - (this.rtl() ? label : 0)
                let v = 0
                if (p.cx < left + EDGE_PX) v = -Math.min(EDGE_MAX_STEP, Math.ceil((left + EDGE_PX - p.cx) / 3))
                else if (p.cx > right - EDGE_PX) v = Math.min(EDGE_MAX_STEP, Math.ceil((p.cx - right + EDGE_PX) / 3))
                // Carried to another row: the rows scroll too, under the sticky header.
                let vy = 0
                if (p.rows) {
                    const top = r.top + (this.ui('refs').body?.getBoundingClientRect ? Math.max(0, this.ui('refs').body.getBoundingClientRect().top - r.top + (el.scrollTop || 0)) : 0)
                    if (p.cy < top + EDGE_PX) vy = -Math.min(EDGE_MAX_STEP, Math.ceil((top + EDGE_PX - p.cy) / 3))
                    else if (p.cy > r.bottom - EDGE_PX) vy = Math.min(EDGE_MAX_STEP, Math.ceil((p.cy - r.bottom + EDGE_PX) / 3))
                }

                if (v || vy) {
                    const before = [el.scrollLeft, el.scrollTop]
                    if (v) el.scrollLeft += v
                    if (vy) el.scrollTop += vy
                    if (el.scrollLeft !== before[0] || el.scrollTop !== before[1]) this.track(p)
                }

                requestAnimationFrame(step)
            }

            requestAnimationFrame(step)
        },

        up(event) {
            const p = this.pointer
            if (! p || event.pointerId !== p.id) return
            this.release(p)

            // A press that never moved is a click: createAt() has it.
            if (p.select) {
                if (p.moved) {
                    // Dragged back to where it was pressed, its click looks like a plain one.
                    this.swallowClick = true
                    this.mountCreate(p.s, p.e, p.group, p.line, p.row)
                }

                return
            }

            if (! p.moved) {
                // Held, then lifted: the bar's menu (touch has no right-click).
                if (p.touch && p.picked) {
                    return this.openMenu({ clientX: p.x0, clientY: p.y0, currentTarget: p.el }, p.bar)
                }

                // A parent's span lies over its row: a click on it is a click on the row, a new
                // bar under the parent there. Where the chart does not create, it opens the parent.
                if (p.bar.parent && this.createAction && ! this.readOnly) {
                    return this.createUnder(p.bar, event, p.el)
                }

                return this.open(p.bar, event)
            }

            if (! p.allowed || (p.bar.s === p.s && p.bar.e === p.e && p.g === p.g0 && p.r === p.r0)) return

            // Dropped on another bar of the row it was carried to: back where it was, with the reason.
            if (p.blocked) {
                p.bar.s = p.s
                p.bar.e = p.e
                this.rebuild()
                this.notify(this.t.row_overlaps || this.t.failed, 'danger')
                return this.say(this.t.row_overlaps || this.t.failed)
            }

            // A parent's span: the parent and its children, by the same amount.
            if (p.family) {
                return this.commitFamily(p.bar.id, p.bar.s - p.s)
            }

            // The line's first piece, or any piece carried to another group: the whole line.
            if (p.line && (p.together || p.g !== p.g0 || p.r !== p.r0)) {
                return this.commitLine(p.bar.id, p.line, p.bar.s - p.s, p.g !== p.g0 ? p.g : null, null, p.r !== p.r0 ? p.r : null)
            }

            this.commit(p.bar, p.s, p.e, p.g !== p.g0 ? p.g : null, p.g0, p.r !== p.r0 ? p.r : null, p.r0)
        },

        /**
         * Abandon the drag in progress (Escape, a lost pointer): the bar goes back,
         * nothing is sent, and the click its release makes on the row (the pointer is
         * no longer captured) creates nothing. A pointer event from another pointer (a
         * second finger lifting) is not ours to act on.
         */
        cancel(event = null) {
            const p = this.pointer
            if (! p || (event?.pointerId !== undefined && event.pointerId !== p.id)) return
            this.release(p)
            if (p.moved || p.select) {
                this.swallowClick = true
            }
            if (p.moved && p.allowed && ! p.select) {
                p.bar.s = p.s
                p.bar.e = p.e
                this.rebuild()
            }
        },

        release(p) {
            this.pointer = null
            this.dragging = false
            this.hint = null
            this.target = null
            this.targetRow = null
            clearTimeout(p.press)
            try { p.el.releasePointerCapture?.(p.id) } catch (e) {}
        },

        /**
         * The arrow keys on a focused bar: ←/→ move it by one unit (mirrored in RTL),
         * Shift moves its end, Alt its start. Keys pressed in a row become one move,
         * sent once they rest; Escape before that puts the bar back.
         */
        nudge(event, bar) {
            if (bar.src._pending || this.pointer || this.readOnly) return
            const vertical = event.key === 'ArrowUp' || event.key === 'ArrowDown'
            const edge = vertical ? null : event.shiftKey ? 'end' : event.altKey ? 'start' : null
            if (edge && bar.milestone) return
            // lineBy(): ↑/↓ carry the whole line, and so do ←/→ on its first piece (as a drag does).
            const entry = this.lineOf(bar)
            // A parent's span moved whole takes its children along, as a drag does (a piece of a line or not).
            const family = ! vertical && ! edge && this.carriesChildren(bar)
            const kids = family ? this.childrenOf(bar) : []
            const line = this.lineDrags && ! edge && ! family ? entry : null
            const together = !! line && (vertical || this.carriesLine(bar))
            const allowed = vertical
                ? ((config.rowLayout || !! config.grouped) && (line ? this.lineRegroupable(line) : !! bar.regroupable))
                : edge ? this.resizes(bar, edge) : (together ? this.lineMovable(line) : family ? bar.movable && kids.every((b) => b.movable && ! b.src._pending) : bar.movable)

            if (! allowed) {
                return this.say(this.t[vertical ? 'not_regroupable' : edge ? 'not_resizable' : 'not_movable'] || '')
            }

            const key = together ? String(line.key) : null
            // Another bar, or the same bar going from its line to itself (or back): the keys so far are sent first.
            // (A span going from itself with its children to one of its edges, or back, likewise.)
            if (this.keyboard && (this.keyboard.id !== bar.id || this.keyboard.line !== key || this.keyboard.family !== family)) {
                this.flushNudge()
                if (bar.src._pending) return
            }
            // The reactive copy, never the plain object just assigned (see down()).
            this.keyboard ??= {
                id: bar.id, s0: bar.s, e0: bar.e, s: bar.s, e: bar.e, g0: String(bar.group ?? ''), g: String(bar.group ?? ''), r0: String(bar.row ?? ''), r: String(bar.row ?? ''), timer: null,
                line: key,
                family,
                mates: key ? this.matesOf(bar) : kids.map((b) => ({ id: b.id, s: b.s, e: b.e })),
                // Where every piece was, for Escape and a refusal.
                groups0: key ? new Map(this.bars.filter((b) => String(b.line) === key).map((b) => [b.id, b.group])) : null,
                // Where every piece's row was (rowBy()).
                rows0: key ? new Map(this.bars.filter((b) => String(b.line) === key).map((b) => [b.id, b.row])) : null,
                walls: this.barWalls(entry, bar, together),
                hold: family ? null : this.childHold(bar),
            }
            const k = this.keyboard

            if (vertical && config.rowLayout) {
                // rowBy(): the previous or next row in the chart's order, across groups when they may change.
                const targets = this.rowTargets(k)
                const at = targets.findIndex((t) => t.g === k.g && t.r === k.r)
                const next = at < 0 ? undefined : targets[at + (event.key === 'ArrowDown' ? 1 : -1)]
                if (next === undefined) return
                k.g = next.g
                k.r = next.r
                if (this.collapsed[next.g]) {
                    this.collapsed[next.g] = false
                    this.persist()
                }
                if (k.line) this.regroupLine(k.line, next.g, bar.id, next.r)
                else this.regroup(bar.src, next.g, next.r)
            } else if (vertical) {
                // The previous or next group in the chart's order; at either end the bar stays.
                const keys = this.groups.map((g) => String(g.key))
                const next = keys[keys.indexOf(k.g) + (event.key === 'ArrowDown' ? 1 : -1)]
                if (next === undefined) return
                k.g = next
                // A folded group is unfolded: the bar stays in view, and keeps the focus.
                if (this.collapsed[next]) {
                    this.collapsed[next] = false
                    this.persist()
                }
                if (k.line) this.regroupLine(k.line, next, bar.id)
                else this.regroup(bar.src, next)
            } else {
                const forward = (event.key === 'ArrowRight') !== this.rtl()
                const unit = k.line ? this.lineUnit(k.line) : this.unitFor(bar)
                const { min, max } = this.limitsFor(bar)
                const d = forward ? unit : -unit
                const walls = k.r !== k.r0 ? null : k.walls

                if (edge === 'end') {
                    k.e = Math.min(Math.max(k.e + d, k.s + min), k.s + max, walls?.right ?? Infinity)
                    if (k.hold) k.e = Math.max(k.e, k.hold.e)
                } else if (edge === 'start') {
                    k.s = Math.max(Math.min(k.s + d, k.e - min), k.e - max, walls?.left ?? -Infinity)
                    if (k.hold) k.s = Math.min(k.s, k.hold.s)
                } else {
                    let step = walls ? Math.min(Math.max(d, walls.left - k.s), walls.right - k.e) : d
                    if (k.hold) step = Math.min(Math.max(step, k.hold.e - k.e), k.hold.s - k.s)
                    k.s += step
                    k.e += step
                }

                bar.s = k.s
                bar.e = k.e
                this.place(bar)
                if (k.mates.length) this.shiftMates(k, k.s - k.s0)
            }

            this.hint = this.dates(this.rowBar(k.id) || bar) + this.placeHint(k)
            this.say(this.hint)

            clearTimeout(k.timer)
            k.timer = setTimeout(() => this.flushNudge(), NUDGE_MS)
        },

        /**
         * Put a bar in another group (and with rowBy() another row) in the browser (optimistically, or back): its row
         * changes, so the chart is rebuilt and, when the bar had the focus, the new
         * element takes it.
         */
        regroup(src, group, row = undefined) {
            const focused = typeof document !== 'undefined' && document.activeElement?.dataset?.id === src.id
            src.group = group
            if (row !== undefined) src.row = row
            this.groups = this.recount()
            this.rebuild()
            if (focused) {
                this.$nextTick(() => this.ui('root').querySelector?.(`.ps-gantt-bar[data-id="${src.id}"]`)?.focus())
            }
        },

        /**
         * Put every piece of a line in a group in the browser (the keys' ↑/↓), or back
         * where each was (a Map of id → group); the focused bar keeps the focus.
         */
        regroupLine(key, group, id, row = undefined) {
            const focused = typeof document !== 'undefined' && document.activeElement?.dataset?.id === id
            for (const src of this.bars) {
                if (String(src.line) !== key) continue
                src.group = group instanceof Map ? (group.get(src.id) ?? src.group) : group
                // rowBy(): the row every piece lands on, or (a Map of id → row) the one each was on.
                if (row !== undefined && row !== null) src.row = row instanceof Map ? (row.get(src.id) ?? src.row) : row
            }
            this.groups = this.recount()
            this.rebuild()
            if (focused) {
                this.$nextTick(() => this.ui('root').querySelector?.(`.ps-gantt-bar[data-id="${id}"]`)?.focus())
            }
        },

        /** Send the keyboard move in progress, if it changed anything. */
        flushNudge() {
            const k = this.keyboard
            if (! k) return
            clearTimeout(k.timer)
            this.keyboard = null
            this.hint = null

            if (k.line) {
                if (k.s === k.s0 && k.g === k.g0 && k.r === k.r0) return this.regroupLine(k.line, k.groups0, k.id, k.rows0)
                return this.commitLine(k.id, k.line, k.s - k.s0, k.g !== k.g0 ? k.g : null, k.groups0, k.r !== k.r0 ? k.r : null, k.rows0)
            }

            if (k.family) {
                return k.s === k.s0 ? this.rebuild() : this.commitFamily(k.id, k.s - k.s0)
            }

            // The row bar, or the source bar when its row is not drawn (a group folded meanwhile).
            const src = this.bars.find((b) => b.id === k.id)
            const bar = this.rowBar(k.id) ?? (src && { ...src, src, s: k.s, e: k.e })
            if (! bar || (k.s === k.s0 && k.e === k.e0 && k.g === k.g0 && k.r === k.r0)) return
            bar.s = k.s
            bar.e = k.e
            this.commit(bar, k.s0, k.e0, k.g !== k.g0 ? k.g : null, k.g0, k.r !== k.r0 ? k.r : null, k.r0)
        },

        /**
         * Send a move: new dates (and, with a group, a new group; with a row, rowBy()'s
         * new row) against where the bar was; the server's answer, or the bar back.
         */
        commit(bar, s0, e0, group = null, g0 = null, row = null, r0 = null) {
            const src = bar.src
            const relocate = group !== null || row !== null
            src._pending = true
            this.pending++
            if (relocate) {
                // The chart is rebuilt from the source bars for the new row (now, or on
                // a rebuild while the move is pending): the new dates go on the source
                // too, or the bar would jump back in time until the server answers.
                src.start = format(bar.s)
                src.end = format(bar.e)
                if ((group !== null && String(src.group ?? '') !== group) || (row !== null && String(src.row ?? '') !== row)) {
                    this.regroup(src, group ?? src.group, row ?? undefined)
                }
            }

            const undo = (message) => {
                src._pending = false
                if (relocate) {
                    if (group !== null) src.group = g0
                    if (row !== null) src.row = r0
                    src.start = format(s0)
                    src.end = format(e0)
                }
                this.groups = this.recount()
                this.rebuild()
                this.notify(message, 'danger')
                this.say(message)
            }

            const args = [bar.id, format(bar.s), bar.milestone ? null : format(bar.e)]
            if (relocate) args.push(group)
            if (row !== null) args.push(row)
            let moved = false

            this.ui('wire').ganttMove(...args)
                .then((result) => {
                    if (! result?.ok) {
                        return undo(result?.message || this.t.failed)
                    }

                    src._pending = false
                    moved = true
                    this.replace(result.bar)
                    this.ui('dispatch')('gantt-bar-moved', { id: bar.id, bar: result.bar })
                    this.flash = bar.id
                    setTimeout(() => { if (this.flash === bar.id) this.flash = null }, 900)
                })
                .catch(() => undo(this.t.offline))
                .finally(() => {
                    this.pending--
                    // ParentSpan::FromChildren, or writeSpanToParent(): a child that moved changes its
                    // parent's span (both, when it changed rows), which only the server knows. Quietly,
                    // once nothing is in the air.
                    if (moved && ! src.parent && (config.parentSpan === 'children' || config.writeSpanToParent)) this.refresh(true)
                })
        },

        /**
         * Send a move of a parent's span with its children (moveChildrenWithParent()): the
         * span and every bar under it shift by `delta` ms in the browser at once; the
         * server checks each (the children outside the window too) and answers with all of
         * them, or refuses and every one goes back.
         */
        commitFamily(id, delta) {
            const before = this.bars
                .filter((src) => (src.parent ? String(src.id) === String(id) : String(src.row ?? '') === String(id)))
                .map((src) => ({ src, start: src.start, end: src.end }))
            const own = before.find((b) => b.src.parent)
            if (! own || ! delta) return
            const shift = (value) => format(parse(value) + delta)
            const start = shift(own.start)

            for (const b of before) {
                b.src.start = shift(b.start)
                b.src.end = shift(b.end)
                b.src._pending = true
            }
            this.pending++
            this.rebuild()

            const undo = (message) => {
                for (const b of before) {
                    b.src.start = b.start
                    b.src.end = b.end
                    b.src._pending = false
                }
                this.rebuild()
                this.notify(message, 'danger')
                this.say(message)
            }

            this.ui('wire').ganttMoveParent(id, start)
                .then((result) => {
                    if (! result?.ok) {
                        return undo(result?.message || this.t.failed)
                    }

                    for (const b of before) b.src._pending = false
                    this.replaceAll(result.bars || [])
                    for (const fresh of result.bars || []) {
                        if (! fresh.computed) this.ui('dispatch')('gantt-bar-moved', { id: fresh.id, bar: fresh })
                    }
                    this.flash = id
                    setTimeout(() => { if (this.flash === id) this.flash = null }, 900)
                })
                .catch(() => undo(this.t.offline))
                .finally(() => this.pending--)
        },

        replace(bar) {
            const { _pending, ...fresh } = bar
            const at = this.bars.findIndex((b) => b.id === fresh.id)
            if (at >= 0) this.bars[at] = fresh
            else this.bars.push(fresh)
            this.syncPiece(fresh)
            this.groups = this.recount()
            this.rebuild()
        },

        /**
         * Send a move of a whole line (lineBy()): every piece shifts by `delta` ms and,
         * with a group, lands in that group, in the browser at once; the server checks
         * every piece and answers with all of them, or refuses and every piece goes back.
         * `groups0` (id → group) is where the pieces were before the keys moved them.
         */
        commitLine(id, key, delta, group = null, groups0 = null, row = null, rows0 = null) {
            const line = this.lineIndex.get(key)
            const before = this.bars
                .filter((src) => String(src.line) === key)
                .map((src) => ({ src, start: src.start, end: src.end, group: groups0?.get(src.id) ?? src.group, row: rows0?.get(src.id) ?? src.row }))
            const pieces = (line?.pieces || []).map((piece) => ({ piece, start: piece.start, end: piece.end, group: piece.group, row: piece.row }))
            const own = before.find((b) => b.src.id === id)
            if (! own) return
            const shift = (value) => format(parse(value) + delta)
            const start = shift(own.start)

            for (const b of before) {
                b.src.start = shift(b.start)
                b.src.end = shift(b.end)
                b.src.group = group ?? b.group
                if (row !== null) b.src.row = row
                b.src._pending = true
            }
            for (const p of pieces) {
                p.piece.start = shift(p.start)
                p.piece.end = shift(p.end)
                if (group !== null) p.piece.group = group
                if (row !== null) p.piece.row = row
            }
            this.pending++
            this.groups = this.recount()
            this.rebuild()

            const undo = (message) => {
                for (const b of before) {
                    b.src.start = b.start
                    b.src.end = b.end
                    b.src.group = b.group
                    if (row !== null) b.src.row = b.row
                    b.src._pending = false
                }
                for (const p of pieces) {
                    p.piece.start = p.start
                    p.piece.end = p.end
                    p.piece.group = p.group
                    if (row !== null) p.piece.row = p.row
                }
                this.groups = this.recount()
                this.rebuild()
                this.notify(message, 'danger')
                this.say(message)
            }

            const args = [id, start]
            if (group !== null || row !== null) args.push(group)
            if (row !== null) args.push(row)

            this.ui('wire').ganttMoveLine(...args)
                .then((result) => {
                    if (! result?.ok) {
                        return undo(result?.message || this.t.failed)
                    }

                    for (const b of before) b.src._pending = false
                    this.replaceAll(result.bars || [])
                    for (const fresh of result.bars || []) this.ui('dispatch')('gantt-bar-moved', { id: fresh.id, bar: fresh })
                    this.flash = id
                    setTimeout(() => { if (this.flash === id) this.flash = null }, 900)
                })
                .catch(() => undo(this.t.offline))
                .finally(() => this.pending--)
        },

        /** The server's bars after a line moved: each replaces its own, a piece that came into the window joins, the line's pieces follow. */
        replaceAll(bars) {
            const from = parse(this.period.start)
            const until = parse(this.period.end) + DAY
            for (const bar of bars) {
                const { _pending, ...fresh } = bar
                const at = this.bars.findIndex((b) => b.id === fresh.id)
                const s = parse(fresh.start)
                if (at >= 0) this.bars[at] = fresh
                else if (s < until && (fresh.milestone ? s >= from : parse(fresh.end) > from)) this.bars.push(fresh)
                this.syncPiece(fresh)
            }
            this.groups = this.recount()
            this.rebuild()
        },

        recount() {
            const counts = {}
            for (const bar of this.bars) {
                if (! bar.computed) counts[String(bar.group ?? '')] = (counts[String(bar.group ?? '')] || 0) + 1
            }
            return this.groups.map((g) => ({ ...g, count: counts[g.key] || 0 }))
        },

        /** Tell assistive technology (the polite live region), even when the words repeat. */
        say(message) {
            this.live = ''
            if (message) this.$nextTick(() => { this.live = message })
        },

        /* ------------------------------------------------------------ Filament actions */

        actionsFor(bar) {
            return this.barActions.filter((a) => ! bar.actions || bar.actions.includes(a.name))
        },

        /** Whether a click on the bar runs the barAction(): it is one the bar offers. The bar's classes ask too. */
        clicks(bar) {
            const name = this.barAction
            return !! name && (! bar.actions || bar.actions.includes(name)) && this.barActions.some((a) => a.name === name)
        },

        runAction(name, bar) {
            this.closeMenu()
            this.ui('wire').mountAction(name, { ganttRecord: bar.id })
        },

        open(bar, event) {
            const url = safeUrl(bar.url)
            const aside = !! (event && (event.metaKey || event.ctrlKey || event.shiftKey))
            // A new tab never gets window.opener: the page it lands on (a user-typed
            // link, say) cannot steer this one.
            const openAside = () => window.open(url, '_blank', 'noopener,noreferrer')

            if (this.clicks(bar)) {
                if (url && aside) {
                    return openAside()
                }
                return this.runAction(this.barAction, bar)
            }

            if (url) {
                aside ? openAside() : (window.location.href = url)
            }
        },

        /* ------------------------------------------------------------ the bar menu */

        // Opened at the pointer (or at the bar, for a keyboard Shift+F10), kept inside
        // the viewport; the first item takes focus, arrows move it, Escape returns it to the bar.
        openMenu(event, bar) {
            if (! this.actionsFor(bar).length) return
            const from = event.currentTarget?.getBoundingClientRect?.()
            const x = event.clientX || from?.left || 0
            const y = event.clientY || from?.bottom || 0
            this.menu = { bar, x, y, from: event.currentTarget ?? null }

            this.$nextTick(() => {
                const el = this.ui('refs').menu
                if (! el || ! this.menu) return
                const w = el.offsetWidth || 0
                const h = el.offsetHeight || 0
                this.menu.x = Math.max(0, Math.min(x, (window.innerWidth || Infinity) - w - 8))
                this.menu.y = Math.max(0, Math.min(y, (window.innerHeight || Infinity) - h - 8))
                this.menuItems()[0]?.focus?.()
            })
        },

        closeMenu(restoreFocus = false) {
            const from = this.menu?.from
            this.menu = null
            if (restoreFocus) from?.focus?.()
        },

        menuItems() {
            return Array.from(this.ui('refs').menu?.querySelectorAll?.('[role="menuitem"]') ?? [])
        },

        /** Move the focus by `step` items, wrapping around; ±Infinity is the first or the last (Home, End). */
        menuMove(step) {
            const items = this.menuItems()
            if (! items.length) return
            const at = items.indexOf(document.activeElement)
            let next
            if (step === -Infinity) next = 0
            else if (step === Infinity) next = items.length - 1
            else next = at < 0 ? (step > 0 ? 0 : items.length - 1) : (at + step + items.length) % items.length
            items[next].focus?.()
        },

        /** Escape: the drag in the air first, then a keyboard move not yet sent, then the menu. */
        onEscape() {
            if (this.pointer) return this.cancel()
            if (this.keyboard) {
                const k = this.keyboard
                clearTimeout(k.timer)
                this.keyboard = null
                this.hint = null
                if (k.line) return this.regroupLine(k.line, k.groups0, k.id, k.rows0)
                const src = this.bars.find((b) => b.id === k.id)
                if (src && (k.g !== k.g0 || k.r !== k.r0)) return this.regroup(src, k.g0, config.rowLayout ? k.r0 : undefined)
                return this.rebuild()
            }
            if (this.menu) this.closeMenu(true)
        },

        /** Whether a click on this row may create: any row, a group's own row too (an empty group has nothing else). */
        creatable(row) {
            return !! this.createAction && ! this.readOnly && !! row
        },

        /** Where the pointer went down, for createAt(): a release elsewhere was a drag, not a click. */
        pressAt(event) {
            this.swallowClick = false
            // A finger wobbles more than a mouse: the same slop as a drag's (track()).
            this.pressed = { x: event.clientX, y: event.clientY, touch: event.pointerType === 'touch' || event.pointerType === 'pen' }
        },

        createAt(event, row) {
            // The release of a drag abandoned with Escape lands on the row: not a click.
            if (this.swallowClick) {
                this.swallowClick = false
                return
            }
            // Pressed somewhere else and released here (a drag across the canvas): not a click either.
            // Used once: a click with no press before it (a keyboard, assistive technology) is a click.
            const pressed = this.pressed
            this.pressed = null
            const travel = pressed ? Math.hypot(event.clientX - pressed.x, event.clientY - pressed.y) : 0
            if (pressed && (pressed.touch ? travel > 8 : travel >= 3)) return
            if (! this.creatable(row) || event.target.closest('.ps-gantt-bar')) return

            const start = this.spotAt(event.clientX, event.currentTarget)

            this.mountCreate(start, start + this.unitDays * DAY, row.group.key || null, this.lineAt(row), this.rowOf(row))
        },

        /**
         * A click on a parent's span: a new bar under that parent, at the snap unit under
         * the pointer (read from the span's own box), in the parent's group.
         */
        createUnder(bar, event, el) {
            const rect = el?.getBoundingClientRect?.()
            const px = rect ? Math.max(0, this.rtl() ? rect.right - event.clientX : event.clientX - rect.left) : 0
            const days = Math.floor(((bar.s - this.view.start) / DAY + px / this.view.pxPerDay) / this.unitDays) * this.unitDays
            const start = this.view.start + days * DAY

            this.mountCreate(start, start + this.unitDays * DAY, String(bar.group ?? '') || null, null, String(bar.id))
        },

        /**
         * A parent task's row stands for its record: what a click on its name and its menu
         * act on ({ id, actions, label }, as a bar), or null for any other row.
         */
        rowRecord(row) {
            return row?.type === 'row' && Array.isArray(row.actions) ? { id: String(row.row), actions: row.actions, label: row.label } : null
        },

        /** Whether a row's name does something: the parent it stands for has an action this user may run. */
        rowActs(row) {
            const record = this.rowRecord(row)
            return !! record && (this.clicks(record) || this.actionsFor(record).length > 0)
        },

        /** A click (or Enter) on a parent's name: its barAction() when it offers it, else its menu. */
        openRow(row, event) {
            const record = this.rowRecord(row)
            if (! record) return
            if (this.clicks(record)) return this.runAction(this.barAction, record)
            this.openMenu(event, record)
        },

        /** A right-click on a parent's name: its menu, when it has one (else the browser's own). */
        rowMenu(row, event) {
            const record = this.rowRecord(row)
            if (! record || ! this.actionsFor(record).length) return
            event.preventDefault?.()
            this.openMenu(event, record)
        },

        /** The row a create on this row lands in (rowBy()): its key, '' on the unassigned row; nothing on a group's own row. */
        rowOf(row) {
            return config.rowLayout && row.type !== 'group' ? String(row.row ?? '') : null
        },

        /** The line a create on this row adds a piece to: a line's row (lineBy()), never a group's own row. */
        lineAt(row) {
            return row.type === 'bar' && row.line !== null && row.line !== undefined ? String(row.line) : null
        },

        mountCreate(start, end, group, line = null, row = null) {
            const args = {
                ganttStart: format(start),
                ganttEnd: format(end),
                ganttGroup: group,
            }
            if (line !== null) args.ganttLine = line
            if (row !== null && row !== undefined) args.ganttRow = row

            this.ui('wire').mountAction(this.createAction.name, args)
        },

        /** The start of the snap unit under a point on screen, kept on the canvas. Read from the canvas's own box, so scrolling is counted. */
        spotAt(clientX, canvas) {
            const rect = canvas.getBoundingClientRect()
            const px = Math.max(0, Math.min(this.rtl() ? rect.right - clientX : clientX - rect.left, this.view.width - 1))
            const days = Math.floor(px / this.view.pxPerDay / this.unitDays) * this.unitDays

            return this.view.start + days * DAY
        },

        /**
         * A press on an empty spot of a row: dragged, it selects the period to create.
         * Lives in `pointer` like a bar's drag (one pointer at a time, Escape, the
         * chart's auto-scroll), without a `bar`. Mouse only: on touch a swipe scrolls.
         */
        selectFrom(event, row) {
            if (event.button !== 0 || event.ctrlKey || event.pointerType !== 'mouse' || this.pointer || ! this.creatable(row) || event.target.closest('.ps-gantt-bar')) return

            const from = this.spotAt(event.clientX, event.currentTarget)

            this.menu = null
            this.pointer = {
                select: true, id: event.pointerId, el: event.currentTarget, key: row.key,
                group: row.group.key || null, line: this.lineAt(row), row: this.rowOf(row), overlaps: false,
                from, s: from, e: from + this.unitDays * DAY,
                allowed: true, moved: false, touch: false, press: null, rows: false, dy: 0,
                x0: event.clientX, y0: event.clientY, cx: event.clientX, cy: event.clientY,
            }
            event.currentTarget.setPointerCapture?.(event.pointerId)
        },

        /** The period between the pressed unit and the one under the pointer, both included, whichever way it went. */
        trackSelection(p) {
            if (! p.moved && Math.hypot(p.cx - p.x0, p.cy - p.y0) < 3) return

            p.moved = true
            this.dragging = true

            const at = this.spotAt(p.cx, p.el)
            p.s = Math.min(p.from, at)
            p.e = Math.max(p.from, at) + this.unitDays * DAY

            p.overlaps = this.selectionOverlaps(p)
            this.hint = this.dates({ s: p.s, e: p.e, allDay: this.unitDays >= 1 }) + (p.group ? ' · ' + this.groupLabel(p.group) : '') + (p.row ? ' · ' + this.rowLabel(p.row) : '')
            this.autoScroll(p)
        },

        /**
         * With preventLineOverlap(), whether the period being selected on a line's row
         * overlaps a piece of that line (with preventRowOverlap(), a bar of that row): the band says so before the form opens (the
         * server still decides when it saves).
         */
        selectionOverlaps(p) {
            // rowBy() with preventRowOverlap(): any bar of the row, in any group (the window's; the server checks the rest).
            if (config.preventRowOverlap && p.row) return this.rowTaken(p.row, null, p.s, p.e)
            if (! config.preventLineOverlap || p.line === null) return false
            const line = this.lineIndex.get(p.line)
            const pieces = line
                ? line.pieces.map((piece) => ({ s: parse(piece.start), e: piece.milestone ? parse(piece.start) : parse(piece.end) }))
                : (this.view.rows.find((row) => row.key === p.key)?.bars || [])
            return pieces.some((piece) => piece.s < p.e && p.s < piece.e)
        },

        selectionStyle() {
            const p = this.pointer
            const x = (p.s - this.view.start) / DAY * this.view.pxPerDay

            return { 'inset-inline-start': x + 'px', width: (p.e - p.s) / DAY * this.view.pxPerDay + 'px' }
        },

        /* ------------------------------------------------------------ window, filters, refresh */

        /**
         * A day picked in the window's From or Until (Filament's date picker, `Y-m-d`
         * or null when cleared). The pickers echo what syncWindow() sets, so a value
         * equal to the one shown is nothing.
         */
        pickRange(edge, value) {
            if (edge !== 'start' && edge !== 'end') return
            const day = value ? String(value).slice(0, 10) : ''
            if (day === (this.range[edge] || '') || (! day && ! this.range[edge])) return
            this.range = { ...this.range, [edge]: day }
            this.applyWindow(null, { user: true })
        },

        /**
         * A filter's field changed (its `ps-gantt-filter` event): a select's value or
         * list, a toggle's boolean, or one edge of a date range. The fields echo what
         * syncFields() sets, so a value equal to the active one is nothing.
         */
        setFilter(name, value, edge = undefined) {
            const filter = this.filters.find((f) => f.name === name)
            if (! filter) return
            if (filter.type === 'dateRange') return this.pickFilter(name, edge, value)
            if (filter.type === 'toggle') {
                const on = value === true
                if ((this.active[name] === true) === on) return
                this.active[name] = on
                return this.refresh()
            }
            this.pickSelect(name, value)
        },

        /** The active filters in the shape the fields hold (null for "all"), set on the component without a request. */
        fieldState() {
            const out = {}
            for (const f of this.filters) {
                const v = this.active[f.name]
                if (f.type === 'dateRange') out[f.name] = { from: v?.from || null, until: v?.until || null }
                else if (f.type === 'toggle') out[f.name] = v === true
                else if (f.multiple) out[f.name] = Array.isArray(v) ? v : []
                else out[f.name] = v === '' || v === null || v === undefined ? null : v
            }
            return out
        },

        /** The fields follow the browser's state (a restored URL, Reset, a pick made elsewhere); no request is sent. */
        syncFields() {
            try { this.ui('wire').$set('ganttFilters', this.fieldState(), false) } catch (e) {}
        },

        /** The From / Until pickers show the window drawn. */
        syncWindow() {
            try { this.ui('wire').$set('ganttWindow', { start: this.period.start || null, end: this.period.end || null }, false) } catch (e) {}
        },

        /**
         * An option chosen in a select or boolean filter (null or '' is "all"); a
         * multiple select hands a list (an empty one is "all").
         */
        pickSelect(name, value) {
            const filter = this.filters.find((f) => f.name === name)
            const v = filter?.multiple
                ? (Array.isArray(value) ? value : (value === null || value === undefined || value === '' ? [] : [value])).map(String)
                : (value === null || value === undefined ? '' : String(value))
            if (JSON.stringify(this.active[name] ?? this.blank(filter || { type: 'select' })) === JSON.stringify(v)) return
            this.active[name] = v
            this.refresh()
        },

        /** A day picked for one end of a date-range filter: the day as `Y-m-d` (from the picker's `Y-m-d H:i:s`), or null when cleared. */
        pickFilter(name, edge, value) {
            if (edge !== 'from' && edge !== 'until') return
            const day = isoDay(value)
            const current = this.active[name] || { from: null, until: null }
            if ((current[edge] || null) === day) return
            this.active[name] = { ...current, [edge]: day }
            this.refresh()
        },

        /** A toggle filter switched on or off. */
        toggleFilter(filter) {
            this.active[filter.name] = this.active[filter.name] !== true
            this.refresh()
        },

        /** Every filter back to "all" (the fields too), then one reload. */
        resetFilters() {
            if (! this.activeFilterCount()) return
            for (const f of this.filters) this.active[f.name] = this.blank(f)
            this.syncFields()
            this.refresh()
        },

        /** The empty state of a filter, what "no choice" looks like: '' / [] / false / { from, until }. */
        blank(filter) {
            if (filter.type === 'dateRange') return { from: null, until: null }
            if (filter.type === 'toggle') return false
            if (filter.multiple) return []
            return ''
        },

        /** Whether a filter narrows the bars. */
        isActive(filter) {
            const v = this.active[filter.name]
            if (filter.type === 'dateRange') return !! (v?.from || v?.until)
            if (filter.type === 'toggle') return v === true
            if (filter.multiple) return Array.isArray(v) && v.length > 0
            return v !== '' && v !== null && v !== undefined
        },

        activeFilterCount() {
            return this.filters.filter((f) => this.isActive(f)).length
        },

        /** What the server gets: the active filters' values in their URL shapes. */
        activeFilters() {
            const out = {}
            for (const f of this.filters) {
                if (! this.isActive(f)) continue
                const v = this.active[f.name]
                if (f.type === 'dateRange') out[f.name] = { from: v.from || null, until: v.until || null }
                else if (f.type === 'toggle') out[f.name] = true
                else out[f.name] = v
            }
            return out
        },

        apply(result) {
            if (! result) return
            const first = this.needsFilters
            if (result.filters) {
                this.filters = result.filters
                this.needsFilters = false
                for (const f of this.filters) {
                    if (! (f.name in this.active)) this.active[f.name] = this.blank(f)
                }
            }
            this.period = result.window
            this.range = { ...result.window }
            this.syncWindow()
            this.groups = result.groups
            this.bars = result.bars
            this.setLines(result.lines)
            this.rows = result.rows || []
            this.total = result.total ?? result.bars.length
            for (const group of this.groups) {
                if (! (group.key in this.collapsed)) this.collapsed[group.key] = !! group.collapsed
            }
            this.rebuild()
            // The filters arrived after the page did: the URL's values for them could
            // not be read at init(). Read now, and reload this window once with them.
            if (result.filters && first && this.restoreFilters()) {
                return this.refresh()
            }
            this.$nextTick(() => this.fill())
            this.syncFields()
        },

        /**
         * Load the window in `range` (the toolbar's dates, Today, a zoom that needs a
         * wider one). The server clamps it; a range the user typed that was cut short
         * says so. Then the chart scrolls to today when it is in view, else to the start.
         */
        applyWindow(after = null, { user = false, keepScroll = false } = {}) {
            const seq = ++this.refreshSeq
            // A range the user typed stays as typed (see fill()); Today or a link's window may fill.
            this.userRange = user
            const asked = this.range.start && this.range.end ? Math.abs(parse(this.range.end) - parse(this.range.start)) / DAY + 1 : null

            // Only a range the user typed (or Fit's span) is shown as asked; any other is at least min_window_days.
            return this.ui('wire').ganttRefresh(this.activeFilters(), this.range.start || null, this.range.end || null, this.needsFilters, user, this.search).then((result) => {
                if (seq !== this.refreshSeq) {
                    if (after) this.filling = false
                    return
                }
                this.apply(result)
                this.persist()

                const days = this.view.days
                if (user && asked && asked > days) {
                    this.notify(this.t.window_limited
                        .replace(':days', days)
                        .replace(':from', phpDate(parse(this.period.start), config.dateFormat, config.locale))
                        .replace(':until', phpDate(parse(this.period.end), config.dateFormat, config.locale)), 'warning')
                }

                this.$nextTick(() => {
                    if (after) return after()
                    if (keepScroll) return
                    const el = this.ui('refs').scroll
                    if (this.view.todayX !== null) this.scrollToToday(false, false)
                    else if (el) el.scrollLeft = 0
                })
            })
        },

        // A filter change reloads whatever the state; a background refresh (a poll,
        // an action) gives way to a bar in the air.
        refresh(background = false) {
            const seq = ++this.refreshSeq
            // The window stays what it is: a typed one stays exact while it is the user's (userRange).
            this.ui('wire').ganttRefresh(this.activeFilters(), this.period.start, this.period.end, this.needsFilters, !! this.userRange, this.search).then((result) => {
                if (seq !== this.refreshSeq || (background && (this.dragging || this.pending || this.keyboard))) return
                this.apply(result)
                if (! background) this.persist()
            })
        },

        /* ------------------------------------------------------------ remembered state */

        // The zoom, the window, the search and the filters live in the URL (shareable);
        // collapsed groups and the last zoom in local storage.
        restore() {
            const asked = { window: false, filters: false }

            try {
                const saved = JSON.parse(localStorage.getItem(config.key) || 'null')
                if (saved) {
                    if (saved.scale && config.pxPerDay[saved.scale] && this.scales.includes(saved.scale)) this.scale = saved.scale
                    if (Number.isFinite(saved.zoom)) this.zoom = Math.min(ZOOM_MAX, Math.max(ZOOM_MIN, saved.zoom))
                    for (const [key, value] of Object.entries(saved.collapsed || {})) {
                        if (key in this.collapsed) this.collapsed[key] = !! value
                    }
                }
            } catch (e) {}

            try {
                const params = new URLSearchParams(window.location.search)
                const scale = params.get('scale')
                if (scale && config.pxPerDay[scale] && this.scales.includes(scale)) this.scale = scale
                const zoom = parseFloat(params.get('zoom'))
                if (Number.isFinite(zoom)) this.zoom = Math.min(ZOOM_MAX, Math.max(ZOOM_MIN, zoom))
                const from = params.get('from')
                const until = params.get('until')
                if ((from && from !== this.period.start) || (until && until !== this.period.end)) {
                    this.range = { start: from || this.period.start, end: until || this.period.end }
                    asked.window = true
                }
                const search = params.get('search')
                if (search && this.searchable) {
                    this.search = search.slice(0, 200)
                    asked.filters = true
                }
                asked.filters = this.restoreFilters(params) || asked.filters
            } catch (e) {}

            return asked
        },

        /** The filters' values from the URL, for the filters known so far; whether any was set. */
        restoreFilters(params = null) {
            let any = false

            try {
                params ??= new URLSearchParams(window.location.search)
                for (const f of this.filters) {
                    if (f.type === 'dateRange') {
                        // a real calendar date only, as the server applies it (nothing else reaches the picker)
                        const a = isoDay(params.get(`f.${f.name}.from`))
                        const b = isoDay(params.get(`f.${f.name}.until`))
                        if (a || b) { this.active[f.name] = { from: a, until: b }; any = true }
                    } else if (f.type === 'toggle') {
                        if (params.get(`f.${f.name}`) === '1') { this.active[f.name] = true; any = true }
                    } else if (f.multiple) {
                        // A listed option, or any value for a searchable select (its options come from a search)
                        const values = params.getAll(`f.${f.name}`).filter((v) => v && (f.searchable || f.options.some((o) => o.value === v)))
                        if (values.length) { this.active[f.name] = [...new Set(values)]; any = true }
                    } else {
                        const v = params.get(`f.${f.name}`)
                        if (v && (f.searchable || f.options.some((o) => o.value === v))) { this.active[f.name] = v; any = true }
                    }
                }
            } catch (e) {}

            return any
        },

        persist() {
            try {
                localStorage.setItem(config.key, JSON.stringify({ scale: this.scale, zoom: this.zoom, collapsed: this.collapsed }))
            } catch (e) {}

            try {
                const url = new URL(window.location.href)
                const params = url.searchParams
                params.set('scale', this.scale)
                if (Math.abs(this.zoom - 1) > 0.001) params.set('zoom', String(Math.round(this.zoom * 1000) / 1000))
                else params.delete('zoom')
                params.set('from', this.period.start)
                params.set('until', this.period.end)
                if (this.search) params.set('search', this.search)
                else params.delete('search')
                for (const f of this.filters) {
                    params.delete(`f.${f.name}`)
                    params.delete(`f.${f.name}.from`)
                    params.delete(`f.${f.name}.until`)
                    if (! this.isActive(f)) continue
                    const v = this.active[f.name]
                    if (f.type === 'dateRange') {
                        if (v.from) params.set(`f.${f.name}.from`, v.from)
                        if (v.until) params.set(`f.${f.name}.until`, v.until)
                    } else if (f.type === 'toggle') {
                        params.set(`f.${f.name}`, '1')
                    } else if (f.multiple) {
                        for (const one of v) params.append(`f.${f.name}`, one)
                    } else {
                        params.set(`f.${f.name}`, v)
                    }
                }
                window.history.replaceState(window.history.state, '', url)
            } catch (e) {}
        },

        /* ------------------------------------------------------------ helpers */

        tone(color) {
            return tone(color)
        },

        notify(message, status) {
            if (window.FilamentNotification) {
                new window.FilamentNotification().title(message)[status]().send()
            }
        },
    }
}
