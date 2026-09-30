/**
 * packstub/filament-gantt — the chart in the browser.
 *
 * The chart is state (a window, groups, bars) drawn by Alpine. All the maths is in
 * one pure function, layout(): it turns bars and a window into pixel columns and
 * rows. A drag changes the bar at once and asks the server afterwards; if the
 * server refuses, the bar goes back and the reason is shown. Pointer events only,
 * no library. Hand-written ES module, no build step.
 *
 * Dates are naive "YYYY-MM-DD HH:MM" strings, read and written as UTC timestamps
 * so daylight-saving changes never shift a bar (the server reads them the same way).
 * A bar is a half-open range [start, end); a milestone has start == end.
 */

export const DAY = 86400000

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
const LONG_PRESS_MS = 500

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

/* ------------------------------------------------------------------ layout */

/**
 * The whole chart as pixels, from the bars and the window. Pure: same input, same
 * output, nothing touched.
 *
 * bars:   [{ id, group, start, end, milestone, label, color, progress, movable, resizable, … }]
 * window: {
 *   start, end            "YYYY-MM-DD", both days included
 *   today                 "YYYY-MM-DD"
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
 * scale:  'day' | 'week' | 'month'
 *
 * Returns { start, days, width, pxPerDay, columns, top, shades, todayX, rows } where
 * rows are { type: 'group' | 'bar' | 'lanes', key, group, label, height, collapsed, bars }
 * and each row bar is the source bar plus { s, e, x, w, lane, src }.
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

    if (scale === 'day') {
        for (let i = 0; i < days; i++) {
            const ts = start + i * DAY
            columns.push({ key: 'c' + ts, x: i * ppd, w: ppd, label: `${weekdayName(ts)} ${new Date(ts).getUTCDate()}`, weekend: weekend.has(isoWeekday(ts)), today: ts === today })
        }
        top = months.map((m) => ({ key: m.key, x: m.x, w: m.w, label: monthLabel(m) }))
    } else if (scale === 'week') {
        let i = 0
        while (i < days) {
            const ts = start + i * DAY
            const len = Math.min(((window.firstDayOfWeek || 1) - isoWeekday(ts) + 7) % 7 || 7, days - i)
            columns.push({ key: 'c' + ts, x: i * ppd, w: len * ppd, label: `${new Date(ts).getUTCDate()} ${monthName(ts)}`, weekend: false, today: false })
            i += len
        }
        top = months.map((m) => ({ key: m.key, x: m.x, w: m.w, label: monthLabel(m) }))
    } else {
        columns = months.map((m) => ({ key: m.key, x: m.x, w: m.w, label: monthName(m.ts), weekend: false, today: false }))
        top = years
    }

    if (scale !== 'month') {
        for (let i = 0; i < days; i++) {
            const ts = start + i * DAY
            if (weekend.has(isoWeekday(ts))) {
                shades.push({ key: 's' + ts, x: i * ppd, w: ppd })
            }
        }
    }

    const todayX = today !== null && today >= start && today < end ? x(today) : null

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

    return { start, days, width, pxPerDay: ppd, columns, top, shades, todayX, rows }
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
        groups: config.groups,
        period: config.window,
        range: { ...config.window },
        filters: config.filters,
        barActions: config.barActions || [],
        barAction: config.barAction,
        createAction: config.createAction,
        readOnly: config.readOnly,
        compact: config.compact,
        height: config.height,
        t: config.i18n,
        scale: config.scale,
        active: {},
        collapsed: {},
        view: { start: 0, days: 0, width: 0, pxPerDay: 1, columns: [], top: [], shades: [], todayX: null, rows: [] },
        slice: { from: 0, to: 0, top: 0, bottom: 0 },
        windowed: false,
        offsets: [],
        dragging: false,
        pointer: null,
        pending: 0,
        flash: null,
        hint: null,
        menu: null,
        refreshSeq: 0,

        init() {
            this.active = Object.fromEntries(this.filters.map((f) => [f.name, f.type === 'dateRange' ? { from: '', until: '' } : '']))

            for (const group of this.groups) {
                this.collapsed[group.key] = !! group.collapsed
            }

            const fromUrl = this.restore()
            this.rebuild()

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
                this.scrollToToday(false)
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
                    if (! document.hidden && ! this.dragging && ! this.pending && ! this.menu) {
                        this.refresh(true)
                    }
                }, config.poll)
            }
        },

        destroy() {
            window.removeEventListener('resize', this.fit)
            clearInterval(this.poller)
        },

        /* ------------------------------------------------------------ drawing */

        get unitDays() {
            return config.unit === 'hour' ? 1 / 24 : 1
        },

        /**
         * The shortest and the longest a resize may make a bar, in ms (the server's
         * minDuration()/maxDuration()), rounded to whole units: a bound of 36 hours at
         * the day unit stops the handle at 2 days, never at 1.5, which the server would
         * snap to 1 and refuse.
         */
        get limits() {
            const unit = this.unitDays * DAY
            const min = Math.ceil(Math.max(unit, (config.minMinutes || 0) * 60000) / unit) * unit
            const max = config.maxMinutes ? Math.max(min, Math.floor(config.maxMinutes * 60000 / unit) * unit) : Infinity

            return { min, max }
        },

        get visibleRows() {
            return this.windowed ? this.view.rows.slice(this.slice.from, this.slice.to) : this.view.rows
        },

        axis() {
            return {
                start: this.period.start,
                end: this.period.end,
                today: config.today,
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

            // A bar in the air (another bar's answer arrived mid-drag) keeps following
            // the pointer: the drag now works on the fresh row bar, at the dragged dates.
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

            // Row windowing above a few hundred rows: only the rows in view are in the DOM.
            this.windowed = this.view.rows.length > 200
            this.offsets = []
            let y = 0
            for (const row of this.view.rows) {
                this.offsets.push(y)
                y += row.height
            }
            this.onScroll()
        },

        onScroll() {
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
            return `inset-inline-start:${c.x}px;width:${c.w}px`
        },

        barStyle(bar) {
            const top = 4 + bar.lane * config.rowHeight
            const inner = config.rowHeight - 8
            const style = `inset-inline-start:${bar.x}px;top:${top}px;height:${inner}px;--ps-bar:${this.tone(bar.color)}`

            // The diamond centres itself on the row's inner height, whatever row_height is.
            return bar.milestone ? style + `;--ps-gantt-row-inner:${inner}px` : style + `;width:${bar.w}px`
        },

        // A whole-day bar reads as dates (its end day is the day before the chart's
        // end, so a one-day bar is one date); a bar with times reads as moments. The
        // record decides, not the snap unit.
        tooltip(bar) {
            const allDay = !! bar.allDay
            const pattern = allDay ? config.dateFormat : config.dateTimeFormat
            const dates = bar.milestone || (allDay && bar.e - bar.s === DAY)
                ? phpDate(bar.s, pattern, config.locale)
                : `${phpDate(bar.s, pattern, config.locale)} – ${phpDate(allDay ? bar.e - DAY : bar.e, pattern, config.locale)}`
            const lines = [bar.label, dates]

            if (bar.milestone) lines.push(this.t.milestone)
            if (bar.progress !== null && bar.progress !== undefined && ! bar.milestone) lines.push(this.t.progress.replace(':percent', bar.progress))
            if (bar.tooltip) lines.push(bar.tooltip)

            return lines.filter(Boolean).join('\n')
        },

        setScale(scale) {
            if (! config.pxPerDay[scale]) return
            const el = this.$refs.scroll
            // scrollLeft counts from the start edge and is negative in RTL: work on its size, keep its sign.
            const dir = this.rtl() ? -1 : 1
            const centre = el ? (Math.abs(el.scrollLeft) + el.clientWidth / 2) / (this.view.pxPerDay || 1) : null
            this.scale = scale
            this.rebuild()
            this.persist()
            if (el && centre !== null) {
                this.$nextTick(() => { el.scrollLeft = dir * Math.max(0, centre * this.view.pxPerDay - el.clientWidth / 2) })
            }
        },

        rtl() {
            return getComputedStyle(this.$root).direction === 'rtl'
        },

        scrollToToday(smooth = true) {
            const el = this.$refs.scroll
            if (! el || this.view.todayX === null) return
            const left = Math.max(0, this.view.todayX - el.clientWidth / 3)
            el.scrollTo({ left: this.rtl() ? -left : left, behavior: smooth ? 'smooth' : 'auto' })
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
            const edge = event.target.dataset?.edge || null
            const allowed = edge ? bar.resizable : bar.movable

            this.menu = null
            const p = { id: event.pointerId, bar, edge, allowed, x0: event.clientX, y0: event.clientY, s: bar.s, e: bar.e, moved: false, dir: this.rtl() ? -1 : 1, el: event.currentTarget, press: null }
            this.pointer = p
            event.currentTarget.setPointerCapture?.(event.pointerId)

            // A finger (or a pen) held still opens the menu: touch has no right-click.
            if (event.pointerType && event.pointerType !== 'mouse' && this.actionsFor(bar).length) {
                p.press = setTimeout(() => {
                    if (this.pointer !== p || p.moved) return
                    this.cancel()
                    this.openMenu({ clientX: p.x0, clientY: p.y0, currentTarget: p.el }, bar)
                }, LONG_PRESS_MS)
            }
        },

        drag(event) {
            const p = this.pointer
            if (! p || event.pointerId !== p.id) return

            const dx = (event.clientX - p.x0) * p.dir
            if (! p.moved && Math.abs(dx) < 3) return

            // Past the threshold it is a drag, allowed or not: the release is never a click.
            p.moved = true
            clearTimeout(p.press)
            if (! p.allowed) return

            this.dragging = true
            const unit = this.unitDays * DAY
            const { min, max } = this.limits
            const delta = Math.round(dx / this.view.pxPerDay / this.unitDays) * unit
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
            this.hint = this.tooltip(bar).split('\n')[1]
        },

        up(event) {
            const p = this.pointer
            if (! p || event.pointerId !== p.id) return
            this.release(p)

            if (! p.moved) {
                return this.open(p.bar, event)
            }

            if (! p.allowed || (p.bar.s === p.s && p.bar.e === p.e)) return

            this.commit(p.bar, p.s, p.e)
        },

        /**
         * Abandon the drag in progress (Escape, a lost pointer): the bar goes back,
         * nothing is sent. A pointer event from another pointer (a second finger
         * lifting) is not ours to act on.
         */
        cancel(event = null) {
            const p = this.pointer
            if (! p || (event?.pointerId !== undefined && event.pointerId !== p.id)) return
            this.release(p)
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
            clearTimeout(p.press)
            try { p.el.releasePointerCapture?.(p.id) } catch (e) {}
        },

        commit(bar, s0, e0) {
            const src = bar.src
            src._pending = true
            this.pending++

            const undo = (message) => {
                src._pending = false
                this.rebuild()
                this.notify(message, 'danger')
            }

            this.$wire.ganttMove(bar.id, format(bar.s), bar.milestone ? null : format(bar.e))
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

        /** Move the focus by `step` items, wrapping around. */
        menuMove(step) {
            const items = this.menuItems()
            if (! items.length) return
            const at = items.indexOf(document.activeElement)
            const next = at < 0 ? (step > 0 ? 0 : items.length - 1) : (at + step + items.length) % items.length
            items[next].focus?.()
        },

        /** Escape: the drag in the air first, then the menu. */
        onEscape() {
            if (this.pointer) return this.cancel()
            if (this.menu) this.closeMenu(true)
        },

        createAt(event, row) {
            if (! this.createAction || row.type === 'group' || event.target.closest('.ps-gantt-bar')) return

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
            this.period = result.window
            this.range = { ...result.window }
            this.groups = result.groups
            this.bars = result.bars
            for (const group of this.groups) {
                if (! (group.key in this.collapsed)) this.collapsed[group.key] = !! group.collapsed
            }
            this.rebuild()
        },

        applyWindow() {
            const seq = ++this.refreshSeq
            this.$wire.ganttWindow(this.range.start || null, this.range.end || null, this.activeFilters()).then((result) => {
                if (seq !== this.refreshSeq) return
                this.apply(result)
                this.persist()
            })
        },

        // A filter change reloads whatever the state; a background refresh (a poll,
        // an action) gives way to a bar in the air.
        refresh(background = false) {
            const seq = ++this.refreshSeq
            this.$wire.ganttRefresh(this.activeFilters(), this.period.start, this.period.end).then((result) => {
                if (seq !== this.refreshSeq || (background && (this.dragging || this.pending))) return
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
                    if (saved.scale && config.pxPerDay[saved.scale]) this.scale = saved.scale
                    for (const [key, value] of Object.entries(saved.collapsed || {})) {
                        if (key in this.collapsed) this.collapsed[key] = !! value
                    }
                }
            } catch (e) {}

            try {
                const params = new URLSearchParams(window.location.search)
                const scale = params.get('scale')
                if (scale && config.pxPerDay[scale]) this.scale = scale
                const from = params.get('from')
                const until = params.get('until')
                if ((from && from !== this.period.start) || (until && until !== this.period.end)) {
                    this.range = { start: from || this.period.start, end: until || this.period.end }
                    asked.window = true
                }
                for (const f of this.filters) {
                    if (f.type === 'dateRange') {
                        const a = params.get(`f.${f.name}.from`) || ''
                        const b = params.get(`f.${f.name}.until`) || ''
                        if (a || b) { this.active[f.name] = { from: a, until: b }; asked.filters = true }
                    } else {
                        const v = params.get(`f.${f.name}`)
                        if (v && f.options.some((o) => o.value === v)) { this.active[f.name] = v; asked.filters = true }
                    }
                }
            } catch (e) {}

            return asked
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
            return TONES[color] ?? color ?? TONES.primary
        },

        notify(message, status) {
            if (window.FilamentNotification) {
                new window.FilamentNotification().title(message)[status]().send()
            }
        },
    }
}
