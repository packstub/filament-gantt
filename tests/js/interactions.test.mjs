import { test } from 'node:test'
import assert from 'node:assert/strict'
import { chart, bar, rowBar, press, element, layout, tone, DAY, HOUR } from './harness.mjs'

/** setTimeout/clearTimeout recorded instead of run: `timers.at(-1).fn()` fires the last one. */
function fakeTimers(run) {
    const timers = []
    const realSet = globalThis.setTimeout
    const realClear = globalThis.clearTimeout
    globalThis.setTimeout = (fn, ms) => { timers.push({ fn, ms }); return timers.length }
    globalThis.clearTimeout = (id) => { if (id) timers[id - 1].fn = null }
    try {
        return run(timers)
    } finally {
        globalThis.setTimeout = realSet
        globalThis.clearTimeout = realClear
    }
}

/** A key on a focused bar. */
const key = (c, b, k, mods = {}) => c.nudge({ key: k, shiftKey: false, altKey: false, ...mods }, b)

/* ------------------------------------------------------------------ pointer glitches */

test('a drag straight up or down is a drag, never a click: no action opens', () => {
    const c = chart({ barAction: 'edit', barActions: [{ name: 'edit' }], bars: [bar()] })
    press(c, rowBar(c)).up(0, {})
    assert.deepEqual(c.wire.mounted, [['edit', { ganttRecord: '1' }]]) // a click still opens it

    c.wire.mounted = []
    c.down({ button: 0, pointerId: 7, clientX: 100, clientY: 50, pointerType: 'mouse', target: { dataset: {} }, currentTarget: element() }, rowBar(c))
    c.drag({ pointerId: 7, clientX: 100, clientY: 200 })
    c.up({ pointerId: 7, clientX: 100, clientY: 200 })

    assert.deepEqual(c.wire.mounted, [])
    assert.deepEqual(c.wire.moves, [])
})

test('the release of a drag abandoned with Escape creates nothing; the next click does', () => {
    const c = chart({ createAction: { name: 'create' }, bars: [bar()] })
    const p = press(c, rowBar(c))
    p.move(96)
    c.onEscape()
    p.up(96) // the pointer is no longer captured: its click lands on the row

    const row = c.view.rows[0]
    const click = { target: { closest: () => null }, currentTarget: { getBoundingClientRect: () => ({ left: 0, right: 1000 }) }, clientX: 100 }
    c.createAt(click, row)
    assert.deepEqual(c.wire.mounted, [])

    c.createAt(click, row)
    assert.deepEqual(c.wire.mounted, [['create', { ganttStart: '2026-10-03 00:00', ganttEnd: '2026-10-04 00:00', ganttGroup: null }]])
})

test('a bar narrower than its handles is moved wherever it is grabbed', () => {
    const c = chart({ scale: 'month', bars: [bar({ start: '2026-10-05', end: '2026-10-06' })] })
    assert.equal(rowBar(c).w, 5)
    assert.equal(c.narrow(rowBar(c)), true)

    const p = press(c, rowBar(c), { edge: 'end' })
    p.move(10)
    p.up(10)

    assert.deepEqual(c.wire.moves, [['1', '2026-10-07 00:00', '2026-10-08 00:00']])

    const wide = chart({ bars: [bar()] })
    assert.equal(wide.narrow(rowBar(wide)), false)
})

test('a click on a group row creates in that group (an empty group has no other row)', () => {
    const c = chart({ grouped: true, createAction: { name: 'create' }, groups: [{ key: '7', label: 'Empty', color: null, collapsed: false }], bars: [] })
    const row = c.view.rows.find((r) => r.type === 'group')

    assert.equal(c.creatable(row), true)
    c.createAt({ target: { closest: () => null }, currentTarget: { getBoundingClientRect: () => ({ left: 0, right: 1000 }) }, clientX: 50 }, row)
    assert.deepEqual(c.wire.mounted, [['create', { ganttStart: '2026-10-02 00:00', ganttEnd: '2026-10-03 00:00', ganttGroup: '7' }]])
})

test('Home and End in the menu go to the first and the last item', () => {
    const c = chart({ barActions: [{ name: 'a' }, { name: 'b' }, { name: 'c' }], bars: [bar()] })
    const items = [element(), element(), element()]
    c.$refs.menu = { offsetWidth: 200, offsetHeight: 100, querySelectorAll: () => items }
    c.openMenu({ clientX: 10, clientY: 10, currentTarget: element() }, rowBar(c))

    c.menuMove(Infinity)
    assert.equal(document.activeElement, items[2])
    c.menuMove(-Infinity)
    assert.equal(document.activeElement, items[0])
})

/* ------------------------------------------------------------------ auto-scroll */

test('a bar dragged while the chart scrolls follows the pointer through the scrolled distance', () => {
    const c = chart({ bars: [bar()] })
    const el = { scrollLeft: 0, clientWidth: 600, querySelector: () => null, getBoundingClientRect: () => ({ left: 0, right: 600 }) }
    c.$refs.scroll = el

    const p = press(c, rowBar(c))
    p.move(48)
    el.scrollLeft = 96 // scrolled by the edge, the pointer did not move
    c.track(c.pointer)
    assert.equal(rowBar(c).s, Date.UTC(2026, 9, 8))

    p.up(48)
    assert.deepEqual(c.wire.moves, [['1', '2026-10-08 00:00', '2026-10-11 00:00']])
})

test('near the chart\'s end edge the drag scrolls it, until the pointer is released', () => {
    const frames = []
    globalThis.requestAnimationFrame = (fn) => frames.push(fn)
    try {
        const c = chart({ bars: [bar()] })
        const el = { scrollLeft: 0, clientWidth: 600, querySelector: () => null, getBoundingClientRect: () => ({ left: 0, right: 600 }) }
        c.$refs.scroll = el

        const p = press(c, rowBar(c), { x: 300 })
        p.move(290) // the pointer at 590: inside the 48px edge
        frames.shift()()
        assert.ok(el.scrollLeft > 0)
        const scrolled = el.scrollLeft
        frames.shift()()
        assert.ok(el.scrollLeft > scrolled)

        p.up(290)
        const after = el.scrollLeft
        frames.shift()?.()
        assert.equal(el.scrollLeft, after)
        assert.equal(c.autoScrolling, false)
    } finally {
        delete globalThis.requestAnimationFrame
    }
})

/* ------------------------------------------------------------------ the keyboard */

test('arrow keys move the bar, Shift moves its end, Alt its start; one move is sent once the keys rest', () => fakeTimers((timers) => {
    const c = chart({ bars: [bar()] })

    key(c, rowBar(c), 'ArrowRight')
    key(c, rowBar(c), 'ArrowRight')
    assert.equal(rowBar(c).s, Date.UTC(2026, 9, 7))
    assert.equal(c.hint, 'Oct 7, 2026 – Oct 9, 2026')
    assert.deepEqual(c.wire.moves, [])

    key(c, rowBar(c), 'ArrowRight', { shiftKey: true })
    key(c, rowBar(c), 'ArrowLeft', { altKey: true })
    assert.equal(rowBar(c).s, Date.UTC(2026, 9, 6))
    assert.equal(rowBar(c).e, Date.UTC(2026, 9, 11))

    timers.filter((t) => t.fn).at(-1).fn()
    assert.deepEqual(c.wire.moves, [['1', '2026-10-06 00:00', '2026-10-11 00:00']])
    assert.equal(c.keyboard, null)
}))

test('a keyboard resize stops at minDuration, and a bar that cannot move says so', () => fakeTimers(() => {
    const c = chart({ minMinutes: 2 * 1440, bars: [bar(), bar({ id: '2', movable: false, resizable: false })] })

    key(c, rowBar(c), 'ArrowLeft', { shiftKey: true })
    key(c, rowBar(c), 'ArrowLeft', { shiftKey: true })
    assert.equal(rowBar(c).e - rowBar(c).s, 2 * DAY)

    key(c, rowBar(c, '2'), 'ArrowRight')
    assert.equal(rowBar(c, '2').s, Date.UTC(2026, 9, 5))
}))

test('arrow keys are mirrored right to left', () => fakeTimers(() => {
    globalThis.__direction = 'rtl'
    try {
        const c = chart({ bars: [bar()] })
        key(c, rowBar(c), 'ArrowLeft')
        assert.equal(rowBar(c).s, Date.UTC(2026, 9, 6))
    } finally {
        globalThis.__direction = 'ltr'
    }
}))

test('Escape before the keys rest puts the bar back and sends nothing', () => fakeTimers((timers) => {
    const c = chart({ bars: [bar()] })
    key(c, rowBar(c), 'ArrowRight')
    c.onEscape()

    assert.equal(rowBar(c).s, Date.UTC(2026, 9, 5))
    timers.filter((t) => t.fn).forEach((t) => t.fn())
    assert.deepEqual(c.wire.moves, [])
}))

test('a whole-day bar moves by days on a chart that snaps to hours', () => fakeTimers(() => {
    const c = chart({ unit: 'hour', minMinutes: 60, bars: [bar(), bar({ id: '2', allDay: false, start: '2026-10-05 08:00', end: '2026-10-05 12:00' })] })

    key(c, rowBar(c), 'ArrowRight')
    assert.equal(rowBar(c).s, Date.UTC(2026, 9, 6))

    key(c, rowBar(c, '2'), 'ArrowRight')
    assert.equal(rowBar(c, '2').s, Date.UTC(2026, 9, 5, 9))
}))

test('the bar\'s accessible name tells how to work it from the keyboard', () => {
    const c = chart({ i18n: { ...chart().t, keyboard: 'Arrow keys move the bar.' }, bars: [bar(), bar({ id: '2', movable: false, resizable: false })] })

    assert.match(c.describe(rowBar(c)), /Arrow keys move the bar\.$/)
    assert.doesNotMatch(c.describe(rowBar(c, '2')), /Arrow keys/)
})

/* ------------------------------------------------------------------ colours */

test('a colour is kept only if it is one; an unknown name is the panel\'s colour of that name', () => {
    assert.equal(tone('sky'), '#0ea5e9')
    assert.equal(tone('danger'), 'var(--danger-500)')
    assert.equal(tone('brand'), 'var(--brand-500, brand)')
    assert.equal(tone('#0ea5e9'), '#0ea5e9')
    assert.equal(tone('oklch(0.6 0.2 250)'), 'oklch(0.6 0.2 250)')
    assert.equal(tone('red;position:fixed;inset:0'), 'var(--primary-500)')
    assert.equal(tone('url(https://example.test/x)'), 'var(--primary-500)')
    assert.equal(tone(null), 'var(--primary-500)')

    const c = chart({ bars: [bar({ color: 'red;position:fixed' })] })
    const style = c.barStyle(rowBar(c))
    assert.equal(typeof style, 'object')
    assert.equal(style['--ps-bar'], 'var(--primary-500)')
    assert.equal(style.position, undefined)
})

/* ------------------------------------------------------------------ the axis and the window */

test('Today outside the window loads a window around today; the first draw never does', () => {
    const c = chart({ window: { start: '2027-03-01', end: '2027-04-30' }, bars: [] })
    assert.equal(c.view.todayX, null)

    c.scrollToToday(false, false)
    assert.deepEqual(c.wire.refreshes, [])

    c.scrollToToday()
    assert.deepEqual(c.wire.refreshes, [[{}, '2026-10-07', '2026-12-06', false]])
})

test('a window narrower than the chart grows to fill it, once per width and zoom', () => {
    const c = chart({ scale: 'month', bars: [bar()] })
    c.$refs.scroll = { clientWidth: 840, querySelector: () => ({ offsetWidth: 240 }) }

    c.fill()
    // 600px at 5px a day: 120 days from the window's start
    assert.deepEqual(c.wire.refreshes, [[{}, '2026-10-01', '2027-01-28', false]])

    c.filling = false
    c.fill()
    assert.equal(c.wire.refreshes.length, 1)
})

test('a range the user typed that the server cut short says so, then the chart scrolls to its start', async () => {
    const notices = []
    const c = chart({ i18n: { ...chart().t, window_limited: 'At most :days days: :from – :until.' }, bars: [] })
    c.notify = (message, status) => notices.push([message, status])
    const el = { scrollLeft: 300, clientWidth: 600, querySelector: () => null, scrollTo() {} }
    c.$refs.scroll = el
    c.$wire.ganttRefresh = () => Promise.resolve({ window: { start: '2027-01-01', end: '2028-12-30' }, groups: [], bars: [], total: 0 })

    c.range = { start: '2027-01-01', end: '2029-06-01' }
    await c.applyWindow(null, { user: true })

    assert.deepEqual(notices, [['At most 730 days: Jan 1, 2027 – Dec 30, 2028.', 'warning']])
    assert.equal(el.scrollLeft, 0)
})

test('only the columns in view are in the DOM when there are thousands (hours over months)', () => {
    const c = chart({ scale: 'hour', window: { start: '2026-10-01', end: '2026-12-31' }, bars: [] })
    c.$refs.scroll = { scrollLeft: 0, clientWidth: 1000, scrollTop: 0, clientHeight: 600 }
    c.rebuild()

    assert.equal(c.view.columns.length, 92 * 24)
    assert.ok(c.visibleColumns.length < 100)
    assert.ok(c.visibleColumns.every((col) => col.x <= 3000))

    c.$refs.scroll.scrollLeft = 50000
    c.onScroll()
    assert.ok(c.visibleColumns.some((col) => col.x >= 50000))
    assert.ok(c.visibleColumns.every((col) => col.x + col.w >= 49000))
})

test('the count reads with the locale\'s plural forms, and says when the window holds more', () => {
    const forms = { one: ':count bar', other: ':count bars' }
    const one = chart({ i18n: { ...chart().t, bars: forms }, bars: [bar()] })
    const two = chart({ i18n: { ...chart().t, bars: forms }, bars: [bar(), bar({ id: '2' })] })
    const cut = chart({ i18n: { ...chart().t, bars: forms, truncated: ':count of :total bars' }, total: 5000, bars: [bar()] })

    assert.equal(one.countText(), '1 bar')
    assert.equal(two.countText(), '2 bars')
    assert.equal(cut.countText(), '1 of 5000 bars')
})

test('a config rendered without filters asks for them with the state, and applies them', () => {
    const c = chart({ filters: null, window: { start: null, end: null }, bars: [] })
    Object.assign(c.view, { days: 0, rows: [] })
    c.init()
    assert.deepEqual(c.wire.refreshes, [[{}, null, null, true]])

    c.apply({ window: { start: '2026-10-01', end: '2026-10-31' }, groups: [], bars: [], total: 0, filters: [{ name: 'status', type: 'select', options: [] }] })
    assert.deepEqual(c.filters.map((f) => f.name), ['status'])
    assert.equal(c.active.status, '')
    assert.equal(c.needsFilters, false)
})

test('a label that does not fit is drawn after the bar, outside the compact layout', () => {
    const c = chart({ bars: [bar({ label: 'A long label for a short bar', start: '2026-10-05', end: '2026-10-06' }), bar({ id: '2', label: 'Ok', start: '2026-10-05', end: '2026-10-10' })] })
    assert.equal(c.labelOutside(rowBar(c)), true)
    assert.equal(c.labelOutside(rowBar(c, '2')), false)

    const compact = chart({ compact: true, bars: [bar({ label: 'A long label for a short bar', start: '2026-10-05', end: '2026-10-06' })] })
    assert.equal(compact.labelOutside(rowBar(compact)), false)
})

/* ------------------------------------------------------------------ the hour scale */

test('hour scale: an hour per column, days on top, the line at now', () => {
    const v = layout([], { start: '2026-10-05', end: '2026-10-06', today: '2026-10-05', now: '2026-10-05 09:30', pxPerDay: 960, rowHeight: 36, weekendDays: [6, 7], locale: 'en', groups: [] }, 'hour')

    assert.equal(v.columns.length, 48)
    assert.equal(v.columns[9].label, '09')
    assert.equal(v.columns[9].x, 9 * 40)
    assert.deepEqual(v.top.map((t) => t.label), ['Mon 5 Oct', 'Tue 6 Oct'])
    assert.equal(v.todayX, 9.5 * 40)
    assert.equal(HOUR * 24, DAY)
})

test('the zoom levels offered come from the server, and a remembered one not offered is ignored', () => {
    const c = chart({ scales: ['hour', 'day'], scale: 'day', bars: [] })
    c.persist = () => {}
    c.setScale('month')
    assert.equal(c.scale, 'day')
    c.setScale('hour')
    assert.equal(c.scale, 'hour')
})

/* ------------------------------------------------------------------ filters and windows */

test('the URL\'s filters apply to a chart whose filters arrived with its state, then the chart reloads once with them', () => {
    const location = globalThis.window.location
    globalThis.window.location = { href: '', search: '?f.status=open' }
    try {
        const c = chart({ filters: null, window: { start: null, end: null }, bars: [] })
        Object.assign(c.view, { days: 0, rows: [] })
        c.init()
        assert.deepEqual(c.wire.refreshes, [[{}, null, null, true]])

        c.apply({ window: { start: '2026-10-01', end: '2026-10-31' }, groups: [], bars: [], total: 0, filters: [{ name: 'status', type: 'select', options: [{ value: 'open', label: 'Open' }] }] })
        assert.equal(c.active.status, 'open')
        assert.deepEqual(c.wire.refreshes.at(-1), [{ status: 'open' }, '2026-10-01', '2026-10-31', false])

        // a later state with the filters again (a refresh asked with them) reads the URL no more
        c.apply({ window: { start: '2026-10-01', end: '2026-10-31' }, groups: [], bars: [], total: 0, filters: [{ name: 'status', type: 'select', options: [{ value: 'open', label: 'Open' }] }] })
        assert.equal(c.wire.refreshes.length, 2)
    } finally {
        globalThis.window.location = location
    }
})

test('a range the user typed is shown as typed, never widened to the chart\'s width; Today may fill again', () => {
    const c = chart({ scale: 'month', bars: [bar()] })
    c.$refs.scroll = { clientWidth: 840, querySelector: () => ({ offsetWidth: 240 }) }

    c.range = { start: '2026-10-01', end: '2026-10-28' }
    c.applyWindow(null, { user: true })
    c.apply({ window: { start: '2026-10-01', end: '2026-10-28' }, groups: [], bars: [], total: 0 })
    assert.equal(c.wire.refreshes.length, 1)
    assert.equal(c.view.days, 28)

    c.applyWindow()
    c.apply({ window: { start: '2026-10-01', end: '2026-10-28' }, groups: [], bars: [], total: 0 })
    assert.equal(c.wire.refreshes.length, 3)
})

test('a keyboard move works on the reactive copy of its state, like a drag', () => fakeTimers(() => {
    const c = chart({ bars: [bar()] })
    key(c, rowBar(c), 'ArrowRight')
    // the timer and the dates were written to what this.keyboard returns, not to the object assigned
    assert.ok(c.keyboard.timer)
    assert.equal(c.keyboard.s, Date.UTC(2026, 9, 6))
}))

/* ------------------------------------------------------------------ moving to another row */

/** Two groups with a bar each; the body starts at y = 0, so row i is at offsets[i]. */
function groupedChart(over = {}) {
    const c = chart({
        grouped: true,
        groups: [{ key: '1', label: 'Acme', color: null, collapsed: false, count: 1 }, { key: '2', label: 'Beta', color: null, collapsed: false, count: 1 }],
        bars: [bar({ id: '1', group: '1', regroupable: true }), bar({ id: '2', group: '2', start: '2026-10-10', end: '2026-10-12', regroupable: true })],
        ...over,
    })
    c.$refs.body = { getBoundingClientRect: () => ({ top: 0 }) }
    return c
}

test('a bar dragged onto another group\'s row lights that group up, is drawn over the row, and lands there on release', () => {
    const c = groupedChart()
    // rows: Acme (0), bar 1 (36), Beta (72), bar 2 (108)
    const p = press(c, rowBar(c, '1'), { y: 50 })
    c.drag({ pointerId: 7, clientX: 100, clientY: 125 })

    assert.equal(c.target, '2')
    assert.equal(c.barStyle(rowBar(c, '1')).transform, 'translateY(72px)')
    assert.equal(c.hint, 'Oct 5, 2026 – Oct 7, 2026 · Beta')
    assert.equal(rowBar(c, '1').s, Date.UTC(2026, 9, 5), 'no horizontal move')

    p.up()
    assert.deepEqual(c.wire.moves, [['1', '2026-10-05 00:00', '2026-10-08 00:00', '2']])
    assert.equal(c.target, null)
    // optimistic: the bar is in Beta's rows now, the counts follow
    assert.equal(c.view.rows.find((r) => r.key === 'b:1').group.key, '2')
    assert.deepEqual(c.groups.map((g) => g.count), [0, 2])
})

test('a refused change of row puts the bar back in its group', async () => {
    const c = groupedChart()
    c.$wire.ganttMove = () => Promise.resolve({ ok: false, message: 'That row is not on the chart.' })
    const notices = []
    c.notify = (m) => notices.push(m)

    const p = press(c, rowBar(c, '1'), { y: 50 })
    c.drag({ pointerId: 7, clientX: 100, clientY: 125 })
    p.up()
    assert.equal(c.bars[0].group, '2')

    await new Promise((r) => setTimeout(r, 0))
    assert.equal(c.bars[0].group, '1')
    assert.deepEqual(c.groups.map((g) => g.count), [1, 1])
    assert.deepEqual(notices, ['That row is not on the chart.'])
})

test('a bar that may not change rows, an edge drag, and an ungrouped chart stay in their row', () => {
    const c = groupedChart({ bars: [bar({ id: '1', group: '1', regroupable: false }), bar({ id: '2', group: '2', regroupable: true })] })
    let p = press(c, rowBar(c, '1'), { y: 50 })
    c.drag({ pointerId: 7, clientX: 148, clientY: 125 })
    assert.equal(c.target, null)
    p.up(48)
    assert.deepEqual(c.wire.moves, [['1', '2026-10-06 00:00', '2026-10-09 00:00']])

    // the end handle resizes, whatever row the pointer crosses
    p = press(c, rowBar(c, '2'), { y: 125, edge: 'end' })
    c.drag({ pointerId: 7, clientX: 148, clientY: 50 })
    assert.equal(c.target, null)
    assert.equal(c.barStyle(rowBar(c, '2')).transform, undefined)
    p.up(48)
    assert.equal(c.wire.moves.at(-1).length, 3)

    // released over its own group's row: no group sent, the dates alone
    p = press(c, rowBar(c, '2'), { y: 125 })
    c.drag({ pointerId: 7, clientX: 148, clientY: 80 })
    assert.equal(c.target, null)
    p.up(48)
    assert.equal(c.wire.moves.at(-1).length, 3)

    const u = chart({ bars: [bar({ regroupable: true })] })
    u.$refs.body = { getBoundingClientRect: () => ({ top: 0 }) }
    p = press(u, rowBar(u), { y: 10 })
    u.drag({ pointerId: 7, clientX: 100, clientY: 500 })
    assert.equal(u.target, null)
})

test('up and down arrows carry a focused bar to the previous or next group, keep its focus, and send the row once the keys rest', () => fakeTimers((timers) => {
    const c = groupedChart()
    const focused = []
    c.$root.querySelector = (selector) => ({ focus: () => focused.push(selector) })
    document.activeElement = { dataset: { id: '1' } }

    key(c, rowBar(c, '1'), 'ArrowDown')
    assert.equal(c.view.rows.find((r) => r.key === 'b:1').group.key, '2')
    assert.equal(c.hint, 'Oct 5, 2026 – Oct 7, 2026 · Beta')
    assert.equal(c.live, c.hint)
    assert.deepEqual(focused, ['.ps-gantt-bar[data-id="1"]'])

    // the last group: it stays
    key(c, rowBar(c, '1'), 'ArrowDown')
    assert.equal(c.view.rows.find((r) => r.key === 'b:1').group.key, '2')

    // dates and row in the same move
    key(c, rowBar(c, '1'), 'ArrowRight')
    timers.filter((t) => t.fn).at(-1).fn()
    assert.deepEqual(c.wire.moves, [['1', '2026-10-06 00:00', '2026-10-09 00:00', '2']])

    // a bar that may not change rows says so
    const d = groupedChart({ bars: [bar({ id: '1', group: '1', regroupable: false })] })
    key(d, rowBar(d, '1'), 'ArrowUp')
    assert.equal(d.live, '')
    d.t.not_regroupable = 'Not to another row.'
    key(d, rowBar(d, '1'), 'ArrowUp')
    assert.equal(d.live, 'Not to another row.')
    assert.deepEqual(d.wire.moves, [])
    document.activeElement = null
}))

test('Escape before the keys rest puts the bar back in its row and sends nothing', () => fakeTimers(() => {
    const c = groupedChart()
    key(c, rowBar(c, '1'), 'ArrowDown')
    assert.equal(c.bars[0].group, '2')

    c.onEscape()
    assert.equal(c.bars[0].group, '1')
    assert.equal(c.keyboard, null)
    assert.deepEqual(c.groups.map((g) => g.count), [1, 1])
    assert.deepEqual(c.wire.moves, [])
}))

test('in the compact layout the target is the group\'s one row', () => {
    const c = groupedChart({ compact: true })
    // rows: Acme lanes (0), Beta lanes (36)
    const p = press(c, rowBar(c, '1'), { y: 10 })
    c.drag({ pointerId: 7, clientX: 100, clientY: 50 })
    assert.equal(c.target, '2')
    assert.equal(c.barStyle(rowBar(c, '1')).transform, 'translateY(36px)')
    p.up()
    assert.deepEqual(c.wire.moves, [['1', '2026-10-05 00:00', '2026-10-08 00:00', '2']])
})
