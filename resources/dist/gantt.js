/**
 * packstub/filament-gantt — the chart in the browser.
 *
 * The chart is state (a window, groups, bars) drawn by Alpine. All the maths is in
 * one pure function, layout(): it turns bars and a window into pixel columns and
 * rows. A drag (or an arrow key) changes the bar at once and asks the server
 * afterwards; if the server refuses, the bar goes back and the reason is shown.
 * Pointer events only, no library. Hand-written ES module, no build step.
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

/* ------------------------------------------------------------------ layout */

/**
 * The whole chart as pixels, from the bars and the window. Pure: same input, same
 * output, nothing touched.
 *
 * bars:   [{ id, group, start, end, milestone, label, color, progress, movable, resizable, … }]
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
 *   collapsed             { [group key]: bool } overriding the groups' own flag
 * }
 * scale:  'hour' | 'day' | 'week' | 'month'
 *
 * Returns { start, days, width, pxPerDay, rowHeight, columns, top, shades, todayX, rows } where
 * rows are { type: 'group' | 'bar' | 'lanes', key, group, label, height, collapsed, bars }
 * and each row bar is the source bar plus { s, e, x, w, lane, src }. An axis label
 * that does not fit its cell is empty.
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
    } else {
        columns = months.map((m) => ({ key: m.key, x: m.x, w: m.w, label: monthName(m.ts), weekend: false, today: false }))
        top = years
    }

    columns = columns.map(cell)
    top = top.map(cell)

    if (scale !== 'month') {
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
        group.count++
        placed.push({ ...bar, s, e, x: x(s), w: bar.milestone ? 0 : Math.max(x(e) - x(s), 4), lane: 0, src: bar })
    }
    if (groups.length === 0) {
        groups.push({ key: '', label: '', color: null, collapsed: false, count: 0 })
    }

    const rows = []
    for (const group of groups) {
        const own = placed.filter((b) => String(b.group ?? '') === group.key)
        const collapsed = !! (window.collapsed && group.key in window.collapsed ? window.collapsed[group.key] : group.collapsed)

        if (window.compact) {
            const lanes = pack(own)
            rows.push({ type: 'lanes', key: 'g:' + group.key, group, label: group.label, height: Math.max(1, lanes) * rowHeight, collapsed: false, bars: own })
            continue
        }

        if (window.grouped) {
            rows.push({ type: 'group', key: 'g:' + group.key, group, label: group.label, height: rowHeight, collapsed, bars: [] })
        }

        if (! collapsed || ! window.grouped) {
            for (const bar of own) {
                rows.push({ type: 'bar', key: 'b:' + bar.id, group, label: bar.label ?? '', height: rowHeight, collapsed: false, bars: [bar] })
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
    const at = new Map()
    let y = 0
    for (const row of view.rows) {
        for (const bar of row.bars) {
            const inner = (row.type === 'lanes' ? rowHeight : row.height) - 8
            at.set(String(bar.id), { bar, y: y + 4 + (bar.lane || 0) * rowHeight + inner / 2 })
        }
        y += row.height
    }

    const out = []
    for (const { bar, y: y2 } of at.values()) {
        for (const dep of bar.deps || []) {
            const from = at.get(String(dep))
            if (! from || from.bar === bar) continue
            const shift = (b) => carried && String(b.id) === String(carried.id) ? carried.dy : 0
            const x1 = from.bar.x + from.bar.w
            const y1 = from.y + shift(from.bar)
            const x2 = bar.x
            const yb = y2 + shift(bar)
            const tip = 'm-6,-4 l6,4 l-6,4'
            // Room for the elbow: straight through when the successor starts after the
            // predecessor ends, otherwise out, around through the gap between the rows, and in.
            const d = x2 - x1 >= ARROW_GAP * 2
                ? `M${x1},${y1} h${ARROW_GAP} V${yb} H${x2} ${tip}`
                : `M${x1},${y1} h${ARROW_GAP} V${(y1 + yb) / 2} H${x2 - ARROW_GAP} V${yb} H${x2} ${tip}`
            out.push({ key: `${dep}>${bar.id}`, d, late: bar.s < from.bar.e })
        }
    }

    return out
}

/** Stack overlapping bars into lanes (greedy, by start); sets bar.lane and returns the lane count. */
export function pack(bars) {
    const ends = []

    for (const bar of [...bars].sort((a, b) => a.x - b.x || a.w - b.w)) {
        const from = bar.milestone ? bar.x - MILESTONE_PX / 2 : bar.x
        const to = bar.milestone ? bar.x + MILESTONE_PX / 2 : bar.x + bar.w
        let lane = ends.findIndex((end) => end <= from)
        if (lane < 0) {
            lane = ends.length
            ends.push(0)
        }
        ends[lane] = to + 2
        bar.lane = lane
    }

    return ends.length
}

/* ------------------------------------------------------------------ Alpine component */

export default function packstubGantt(config) {
    return {
        bars: config.bars,
        total: config.total ?? config.bars.length,
        groups: config.groups,
        period: config.window,
        range: { ...config.window },
        // A config rendered after the chart's first render carries no filters: they
        // come with the state the chart then asks for (see refresh()).
        filters: config.filters || [],
        needsFilters: config.filters === null,
        barActions: config.barActions || [],
        barAction: config.barAction,
        createAction: config.createAction,
        readOnly: config.readOnly,
        compact: config.compact,
        height: config.height,
        scales: config.scales || ['day', 'week', 'month'],
        t: config.i18n,
        scale: config.scale,
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
        swallowClick: false,
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
            this.active = Object.fromEntries(this.filters.map((f) => [f.name, this.blank(f)]))

            for (const group of this.groups) {
                this.collapsed[group.key] = !! group.collapsed
            }

            const fromUrl = this.restore()

            // A config without a window has nothing to draw yet: the server's answer
            // (apply()) draws it, instead of a one-column, no-row chart for the round trip.
            if (this.period.start) {
                this.rebuild()
            }

            this.fit = () => {
                if (this.height) return
                const el = this.$refs.scroll
                if (! el) return
                const top = el.getBoundingClientRect().top + window.scrollY
                this.$root.style.setProperty('--ps-gantt-height', Math.max(320, window.innerHeight - top - 24) + 'px')
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
            return arrows(this.view, p && p.dy ? { id: p.bar.id, dy: p.dy } : null)
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
                pxPerDay: config.pxPerDay[this.scale] || 18,
                rowHeight: config.rowHeight,
                firstDayOfWeek: config.firstDayOfWeek,
                weekendDays: config.weekendDays,
                locale: config.locale,
                groups: this.groups,
                grouped: config.grouped,
                compact: config.compact,
                collapsed: this.collapsed,
            }
        },

        rebuild() {
            this.view = layout(this.bars, this.axis(), this.scale)

            // A bar in the air (another bar's answer arrived mid-drag), or one being
            // nudged with the keys, keeps its new dates on the fresh row bar.
            const p = this.pointer
            if (p) {
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

            const el = this.$refs.scroll
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

            const el = this.$refs.scroll
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

        /** Pixels from a row bar's dates. */
        place(bar) {
            bar.x = ((bar.s - this.view.start) / DAY) * this.view.pxPerDay
            bar.w = bar.milestone ? 0 : Math.max(((bar.e - bar.s) / DAY) * this.view.pxPerDay, 4)
        },

        cell(c) {
            return { 'inset-inline-start': c.x + 'px', width: c.w + 'px' }
        },

        // An object, never a string: Alpine sets each property on its own, so a value
        // (a colour from user data) can never add declarations of its own.
        barStyle(bar) {
            const top = 4 + bar.lane * config.rowHeight
            const inner = config.rowHeight - 8
            const style = { 'inset-inline-start': bar.x + 'px', top: top + 'px', height: inner + 'px', '--ps-bar': this.tone(bar.color) }

            // Carried to another row: drawn over that row while the pointer is down.
            const p = this.pointer
            if (p && p.dy && p.bar.id === bar.id) {
                style.transform = `translateY(${p.dy}px)`
            }

            // The diamond centres itself on the row's inner height, whatever row_height is.
            if (bar.milestone) {
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
         * in the compact layout: the next bar of the lane may sit right there.
         */
        labelOutside(bar) {
            if (bar.milestone || this.compact || ! bar.label) return false

            return this.textWidth(bar.label) + 18 > bar.w
        },

        textWidth(text) {
            this.widths ??= new Map()
            if (this.widths.has(text)) return this.widths.get(text)

            let width = String(text).length * 6.6
            try {
                this.ruler ??= typeof document.createElement === 'function' ? document.createElement('canvas').getContext('2d') : null
                if (this.ruler) {
                    this.ruler.font = `500 12px ${getComputedStyle(this.$root).fontFamily || 'sans-serif'}`
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

            return bar.milestone || (allDay && bar.e - bar.s === DAY)
                ? phpDate(bar.s, pattern, config.locale)
                : `${phpDate(bar.s, pattern, config.locale)} – ${phpDate(allDay ? bar.e - DAY : bar.e, pattern, config.locale)}`
        },

        /** The bar's accessible name: what it is, then how to work it from the keyboard. */
        describe(bar) {
            const name = this.tooltip(bar)

            return ! this.readOnly && (bar.movable || bar.resizable) && this.t.keyboard ? `${name}\n${this.t.keyboard}` : name
        },

        countText() {
            const n = this.bars.length

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
            const el = this.$refs.scroll
            // scrollLeft counts from the start edge and is negative in RTL: work on its size, keep its sign.
            const dir = this.rtl() ? -1 : 1
            const centre = el ? (Math.abs(el.scrollLeft) + el.clientWidth / 2) / (this.view.pxPerDay || 1) : null
            this.scale = scale
            this.rebuild()
            this.persist()
            if (el && centre !== null) {
                this.$nextTick(() => {
                    el.scrollLeft = dir * Math.max(0, centre * this.view.pxPerDay - el.clientWidth / 2)
                    this.onScroll()
                    this.fill()
                })
            }
        },

        rtl() {
            return getComputedStyle(this.$root).direction === 'rtl'
        },

        /** Width of the sticky label column, in px. */
        labelWidth() {
            return this.$refs.scroll?.querySelector?.('.ps-gantt-corner')?.offsetWidth || 0
        },

        /**
         * Today into view. Outside the window, the window moves to today first (unless
         * `load` is false: the first draw never changes the window by itself).
         */
        scrollToToday(smooth = true, load = true) {
            const el = this.$refs.scroll

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
            const el = this.$refs.scroll
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

        down(event, bar) {
            // One pointer at a time: a second finger neither starts a drag nor ends the first.
            if (event.button !== 0 || bar.src._pending || this.pointer) return
            this.flushNudge()
            // A bar too narrow for its handles is moved, whatever part of it was grabbed.
            const edge = this.narrow(bar) ? null : (event.target.dataset?.edge || null)
            const allowed = edge ? bar.resizable : bar.movable
            const touch = event.pointerType === 'touch' || event.pointerType === 'pen'

            this.menu = null
            let p = {
                id: event.pointerId, bar, edge, allowed, touch, picked: false, moved: false, press: null,
                x0: event.clientX, y0: event.clientY, cx: event.clientX, cy: event.clientY,
                s: bar.s, e: bar.e, sl0: this.$refs.scroll?.scrollLeft || 0,
                // A whole bar on a grouped chart may be carried to another row.
                rows: ! edge && !! bar.regroupable && !! config.grouped, g0: String(bar.group ?? ''), g: String(bar.group ?? ''), dy: 0,
                dir: this.rtl() ? -1 : 1, el: event.currentTarget,
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
            const scrolled = (this.$refs.scroll?.scrollLeft || 0) - p.sl0
            const dx = (sx + scrolled) * p.dir
            const unit = this.unitFor(p.bar)
            const { min, max } = this.limitsFor(p.bar)
            const delta = Math.round(dx / this.view.pxPerDay * DAY / unit) * unit
            const bar = p.bar

            if (p.edge === 'start') {
                bar.s = Math.max(Math.min(p.s + delta, p.e - min), p.e - max)
                bar.e = p.e
            } else if (p.edge === 'end') {
                bar.s = p.s
                bar.e = Math.min(Math.max(p.e + delta, p.s + min), p.s + max)
            } else {
                bar.s = p.s + delta
                bar.e = p.e + delta
            }

            this.place(bar)

            if (p.rows) {
                const at = this.rowAt(p.cy)
                const own = this.view.rows.findIndex((row) => row.bars.includes(bar))
                const other = at !== null && this.view.rows[at].group.key !== p.g0
                this.target = other ? this.view.rows[at].group.key : null
                p.g = other ? this.target : p.g0
                p.dy = other && own >= 0 ? this.offsets[at] - this.offsets[own] : 0
            }

            this.hint = this.dates(bar) + (p.g !== p.g0 ? ' · ' + this.groupLabel(p.g) : '')
            this.autoScroll(p)
        },

        /** The index of the row under a point on screen, or null outside the rows. */
        rowAt(clientY) {
            const body = this.$refs.body
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
            const el = this.$refs.scroll
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
                    const top = r.top + (this.$refs.body?.getBoundingClientRect ? Math.max(0, this.$refs.body.getBoundingClientRect().top - r.top + (el.scrollTop || 0)) : 0)
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

            if (! p.moved) {
                // Held, then lifted: the bar's menu (touch has no right-click).
                if (p.touch && p.picked) {
                    return this.openMenu({ clientX: p.x0, clientY: p.y0, currentTarget: p.el }, p.bar)
                }

                return this.open(p.bar, event)
            }

            if (! p.allowed || (p.bar.s === p.s && p.bar.e === p.e && p.g === p.g0)) return

            this.commit(p.bar, p.s, p.e, p.g !== p.g0 ? p.g : null, p.g0)
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
            if (p.moved) {
                this.swallowClick = true
            }
            if (p.moved && p.allowed) {
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
            const allowed = vertical ? bar.regroupable && config.grouped : edge ? bar.resizable : bar.movable

            if (! allowed) {
                return this.say(this.t[vertical ? 'not_regroupable' : edge ? 'not_resizable' : 'not_movable'] || '')
            }

            if (this.keyboard && this.keyboard.id !== bar.id) this.flushNudge()
            // The reactive copy, never the plain object just assigned (see down()).
            this.keyboard ??= { id: bar.id, s0: bar.s, e0: bar.e, s: bar.s, e: bar.e, g0: String(bar.group ?? ''), g: String(bar.group ?? ''), timer: null }
            const k = this.keyboard

            if (vertical) {
                // The previous or next group in the chart's order; at either end the bar stays.
                const keys = this.groups.map((g) => String(g.key))
                const next = keys[keys.indexOf(k.g) + (event.key === 'ArrowDown' ? 1 : -1)]
                if (next === undefined) return
                k.g = next
                this.regroup(bar.src, next)
            } else {
                const forward = (event.key === 'ArrowRight') !== this.rtl()
                const unit = this.unitFor(bar)
                const { min, max } = this.limitsFor(bar)
                const d = forward ? unit : -unit

                if (edge === 'end') {
                    k.e = Math.min(Math.max(k.e + d, k.s + min), k.s + max)
                } else if (edge === 'start') {
                    k.s = Math.max(Math.min(k.s + d, k.e - min), k.e - max)
                } else {
                    k.s += d
                    k.e += d
                }

                bar.s = k.s
                bar.e = k.e
                this.place(bar)
            }

            this.hint = this.dates(this.rowBar(k.id) || bar) + (k.g !== k.g0 ? ' · ' + this.groupLabel(k.g) : '')
            this.say(this.hint)

            clearTimeout(k.timer)
            k.timer = setTimeout(() => this.flushNudge(), NUDGE_MS)
        },

        /**
         * Put a bar in another group in the browser (optimistically, or back): its row
         * changes, so the chart is rebuilt and, when the bar had the focus, the new
         * element takes it.
         */
        regroup(src, group) {
            const focused = typeof document !== 'undefined' && document.activeElement?.dataset?.id === src.id
            src.group = group
            this.groups = this.recount()
            this.rebuild()
            if (focused) {
                this.$nextTick(() => this.$root.querySelector?.(`.ps-gantt-bar[data-id="${src.id}"]`)?.focus())
            }
        },

        /** Send the keyboard move in progress, if it changed anything. */
        flushNudge() {
            const k = this.keyboard
            if (! k) return
            clearTimeout(k.timer)
            this.keyboard = null
            this.hint = null

            const bar = this.rowBar(k.id)
            if (! bar || (k.s === k.s0 && k.e === k.e0 && k.g === k.g0)) return
            bar.s = k.s
            bar.e = k.e
            this.commit(bar, k.s0, k.e0, k.g !== k.g0 ? k.g : null, k.g0)
        },

        /** Send a move: new dates (and, with a group, a new row) against where the bar was; the server's answer, or the bar back. */
        commit(bar, s0, e0, group = null, g0 = null) {
            const src = bar.src
            src._pending = true
            this.pending++
            if (group !== null && String(src.group ?? '') !== group) {
                // The chart is rebuilt from the source bars for the new row: the new
                // dates go on the source too, or the bar would jump back in time until
                // the server answers.
                src.start = format(bar.s)
                src.end = format(bar.e)
                this.regroup(src, group)
            }

            const undo = (message) => {
                src._pending = false
                if (group !== null) {
                    src.group = g0
                    src.start = format(s0)
                    src.end = format(e0)
                }
                this.groups = this.recount()
                this.rebuild()
                this.notify(message, 'danger')
                this.say(message)
            }

            const args = [bar.id, format(bar.s), bar.milestone ? null : format(bar.e)]
            if (group !== null) args.push(group)

            this.$wire.ganttMove(...args)
                .then((result) => {
                    if (! result?.ok) {
                        return undo(result?.message || this.t.failed)
                    }

                    src._pending = false
                    this.replace(result.bar)
                    this.$dispatch('gantt-bar-moved', { id: bar.id, bar: result.bar })
                    this.flash = bar.id
                    setTimeout(() => { if (this.flash === bar.id) this.flash = null }, 900)
                })
                .catch(() => undo(this.t.offline))
                .finally(() => this.pending--)
        },

        replace(bar) {
            const { _pending, ...fresh } = bar
            const at = this.bars.findIndex((b) => b.id === fresh.id)
            if (at >= 0) this.bars[at] = fresh
            else this.bars.push(fresh)
            this.groups = this.recount()
            this.rebuild()
        },

        recount() {
            const counts = {}
            for (const bar of this.bars) counts[String(bar.group ?? '')] = (counts[String(bar.group ?? '')] || 0) + 1
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

        runAction(name, bar) {
            this.closeMenu()
            this.$wire.mountAction(name, { ganttRecord: bar.id })
        },

        open(bar, event) {
            const url = safeUrl(bar.url)
            const aside = !! (event && (event.metaKey || event.ctrlKey || event.shiftKey))
            // A new tab never gets window.opener: the page it lands on (a user-typed
            // link, say) cannot steer this one.
            const openAside = () => window.open(url, '_blank', 'noopener,noreferrer')

            if (this.barAction && this.actionsFor(bar).some((a) => a.name === this.barAction)) {
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
                const el = this.$refs.menu
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
            return Array.from(this.$refs.menu?.querySelectorAll?.('[role="menuitem"]') ?? [])
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
                const src = this.bars.find((b) => b.id === k.id)
                if (src && k.g !== k.g0) return this.regroup(src, k.g0)
                return this.rebuild()
            }
            if (this.menu) this.closeMenu(true)
        },

        /** Whether a click on this row may create: any row, a group's own row too (an empty group has nothing else). */
        creatable(row) {
            return !! this.createAction && ! this.readOnly && !! row
        },

        createAt(event, row) {
            // The release of a drag abandoned with Escape lands on the row: not a click.
            if (this.swallowClick) {
                this.swallowClick = false
                return
            }
            if (! this.creatable(row) || event.target.closest('.ps-gantt-bar')) return

            const rect = event.currentTarget.getBoundingClientRect()
            const px = this.rtl() ? rect.right - event.clientX : event.clientX - rect.left
            const days = Math.floor(px / this.view.pxPerDay / this.unitDays) * this.unitDays
            const start = this.view.start + days * DAY
            const end = start + this.unitDays * DAY

            this.$wire.mountAction(this.createAction.name, {
                ganttStart: format(start),
                ganttEnd: format(end),
                ganttGroup: row.group.key || null,
            })
        },

        /* ------------------------------------------------------------ window, filters, refresh */

        blank(filter) {
            return filter.type === 'dateRange' ? { from: '', until: '' } : ''
        },

        activeFilters() {
            const out = {}
            for (const f of this.filters) {
                const v = this.active[f.name]
                if (f.type === 'dateRange') {
                    if (v?.from || v?.until) out[f.name] = { from: v.from || null, until: v.until || null }
                } else if (v) {
                    out[f.name] = v
                }
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
            this.groups = result.groups
            this.bars = result.bars
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

            return this.$wire.ganttRefresh(this.activeFilters(), this.range.start || null, this.range.end || null, this.needsFilters).then((result) => {
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
                    const el = this.$refs.scroll
                    if (this.view.todayX !== null) this.scrollToToday(false, false)
                    else if (el) el.scrollLeft = 0
                })
            })
        },

        // A filter change reloads whatever the state; a background refresh (a poll,
        // an action) gives way to a bar in the air.
        refresh(background = false) {
            const seq = ++this.refreshSeq
            this.$wire.ganttRefresh(this.activeFilters(), this.period.start, this.period.end, this.needsFilters).then((result) => {
                if (seq !== this.refreshSeq || (background && (this.dragging || this.pending || this.keyboard))) return
                this.apply(result)
                if (! background) this.persist()
            })
        },

        /* ------------------------------------------------------------ remembered state */

        // The zoom, the window and the filters live in the URL (shareable); collapsed
        // groups and the last zoom in local storage.
        restore() {
            const asked = { window: false, filters: false }

            try {
                const saved = JSON.parse(localStorage.getItem(config.key) || 'null')
                if (saved) {
                    if (saved.scale && config.pxPerDay[saved.scale] && this.scales.includes(saved.scale)) this.scale = saved.scale
                    for (const [key, value] of Object.entries(saved.collapsed || {})) {
                        if (key in this.collapsed) this.collapsed[key] = !! value
                    }
                }
            } catch (e) {}

            try {
                const params = new URLSearchParams(window.location.search)
                const scale = params.get('scale')
                if (scale && config.pxPerDay[scale] && this.scales.includes(scale)) this.scale = scale
                const from = params.get('from')
                const until = params.get('until')
                if ((from && from !== this.period.start) || (until && until !== this.period.end)) {
                    this.range = { start: from || this.period.start, end: until || this.period.end }
                    asked.window = true
                }
                asked.filters = this.restoreFilters(params)
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
                        const a = params.get(`f.${f.name}.from`) || ''
                        const b = params.get(`f.${f.name}.until`) || ''
                        if (a || b) { this.active[f.name] = { from: a, until: b }; any = true }
                    } else {
                        const v = params.get(`f.${f.name}`)
                        if (v && f.options.some((o) => o.value === v)) { this.active[f.name] = v; any = true }
                    }
                }
            } catch (e) {}

            return any
        },

        persist() {
            try {
                localStorage.setItem(config.key, JSON.stringify({ scale: this.scale, collapsed: this.collapsed }))
            } catch (e) {}

            try {
                const url = new URL(window.location.href)
                const params = url.searchParams
                params.set('scale', this.scale)
                params.set('from', this.period.start)
                params.set('until', this.period.end)
                for (const f of this.filters) {
                    params.delete(`f.${f.name}`)
                    params.delete(`f.${f.name}.from`)
                    params.delete(`f.${f.name}.until`)
                    const v = this.active[f.name]
                    if (f.type === 'dateRange') {
                        if (v?.from) params.set(`f.${f.name}.from`, v.from)
                        if (v?.until) params.set(`f.${f.name}.until`, v.until)
                    } else if (v) {
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
