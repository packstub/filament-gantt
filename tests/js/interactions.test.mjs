import { test } from 'node:test'
import assert from 'node:assert/strict'
import { chart, bar, rowBar, press, element, layout, tone, parse, DAY, HOUR } from './harness.mjs'

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

test('a press on one spot released on another (a drag across the canvas) creates nothing; a click does', () => {
    const c = chart({ createAction: { name: 'create' }, bars: [bar()] })
    const row = c.view.rows[0]
    const click = (x, y = 0) => ({ target: { closest: () => null }, currentTarget: { getBoundingClientRect: () => ({ left: 0, right: 1000 }) }, clientX: x, clientY: y })

    c.pressAt({ clientX: 40, clientY: 0 })
    c.createAt(click(100), row)
    c.pressAt({ clientX: 100, clientY: 0 })
    c.createAt(click(100, 60), row)
    assert.deepEqual(c.wire.mounted, [])

    c.pressAt({ clientX: 99, clientY: 1 })
    c.createAt(click(100), row)
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

test('a tap that wobbles like a finger creates; a mouse press that slid as far does not; a click with no press creates', () => {
    const c = chart({ createAction: { name: 'create' }, bars: [bar()] })
    const row = c.view.rows[0]
    const click = (x, y = 0) => ({ target: { closest: () => null }, currentTarget: { getBoundingClientRect: () => ({ left: 0, right: 1000 }) }, clientX: x, clientY: y })

    c.pressAt({ clientX: 94, clientY: 0, pointerType: 'mouse' })
    c.createAt(click(100), row)
    assert.deepEqual(c.wire.mounted, [])

    c.pressAt({ clientX: 94, clientY: 0, pointerType: 'touch' })
    c.createAt(click(100), row)
    assert.equal(c.wire.mounted.length, 1)

    c.pressAt({ clientX: 80, clientY: 0, pointerType: 'touch' })
    c.createAt(click(100), row)
    assert.equal(c.wire.mounted.length, 1)

    // the press is used once: a later click with no pointerdown (a keyboard, assistive technology) is not measured against it
    c.createAt(click(500), row)
    assert.equal(c.wire.mounted.length, 2)
})

test('a bar that started before the lock keeps its start: its end handle drags, its start handle and Alt+arrows do not', () => fakeTimers((timers) => {
    const c = chart({ bars: [bar({ movable: false, resizable: true, startFixed: true })] })
    const b = rowBar(c)
    assert.equal(c.resizes(b, 'end'), true)
    assert.equal(c.resizes(b, 'start'), false)

    const end = press(c, b, { edge: 'end' })
    end.move(96)
    end.up(96)
    assert.deepEqual(c.wire.moves, [['1', '2026-10-05 00:00', '2026-10-10 00:00']])

    c.wire.moves = []
    rowBar(c).src._pending = false
    const start = press(c, rowBar(c), { edge: 'start' })
    start.move(-96)
    start.up(-96)
    assert.deepEqual(c.wire.moves, [])
    assert.equal(rowBar(c).s, Date.UTC(2026, 9, 5))

    key(c, rowBar(c), 'ArrowLeft', { altKey: true })
    assert.equal(rowBar(c).s, Date.UTC(2026, 9, 5))
    assert.equal(c.keyboard, null)
}))

test('only a click on a bar that offers the barAction runs it, and only such a bar is drawn clickable', () => {
    const c = chart({ barAction: 'edit', barActions: [{ name: 'edit' }, { name: 'delete' }], bars: [bar(), bar({ id: '2', actions: ['delete'] })] })
    assert.equal(c.clicks(rowBar(c)), true)
    assert.equal(c.clicks(rowBar(c, '2')), false)

    c.open(rowBar(c, '2'))
    assert.deepEqual(c.wire.mounted, [])
    c.open(rowBar(c))
    assert.deepEqual(c.wire.mounted, [['edit', { ganttRecord: '1' }]])
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
    assert.deepEqual(c.wire.refreshes, [[{}, '2026-10-07', '2026-12-06', false, false]])
})

test('a window narrower than the chart grows to fill it, once per width and zoom', () => {
    const c = chart({ scale: 'month', bars: [bar()] })
    c.$refs.scroll = { clientWidth: 840, querySelector: () => ({ offsetWidth: 240 }) }

    c.fill()
    // 600px at 5px a day: 120 days from the window's start
    assert.deepEqual(c.wire.refreshes, [[{}, '2026-10-01', '2027-01-28', false, false]])

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
    assert.deepEqual(c.wire.refreshes, [[{}, null, null, true, false]])

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
        assert.deepEqual(c.wire.refreshes, [[{}, null, null, true, false]])

        c.apply({ window: { start: '2026-10-01', end: '2026-10-31' }, groups: [], bars: [], total: 0, filters: [{ name: 'status', type: 'select', options: [{ value: 'open', label: 'Open' }] }] })
        assert.equal(c.active.status, 'open')
        assert.deepEqual(c.wire.refreshes.at(-1), [{ status: 'open' }, '2026-10-01', '2026-10-31', false, false])

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

test('a key handler whose bar was destroyed by its own regroup (compact rows) still finds the chart and sends the move', () => fakeTimers((timers) => {
    const c = groupedChart({ compact: true })
    const focused = []
    const root = { style: { setProperty() {} }, querySelector: (selector) => ({ focus: () => focused.push(selector) }) }
    const wire = c.$wire
    c.$root = root
    c.init() // keeps the chart's own $root, $refs, $wire and $dispatch
    document.activeElement = { dataset: { id: '1' } }

    // Alpine resolves the magics from the element the expression ran on: once the bar's
    // element is gone (its row was rebuilt), they resolve to nothing.
    c.$root = undefined
    c.$wire = undefined
    c.$refs = undefined
    c.$dispatch = undefined

    key(c, rowBar(c, '1'), 'ArrowDown')
    assert.equal(c.view.rows.find((r) => r.key === 'b:1' || r.key === 'g:2')?.group.key ?? c.bars[0].group, '2')
    assert.deepEqual(focused, ['.ps-gantt-bar[data-id="1"]'])

    timers.filter((t) => t.fn).at(-1).fn()
    assert.deepEqual(c.wire.moves, [['1', '2026-10-05 00:00', '2026-10-08 00:00', '2']])
    assert.equal(c.bars[0]._pending, true, 'sent, waiting for the answer')
    document.activeElement = null
    c.$wire = wire
    c.destroy()
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

/* ------------------------------------------------------------------ dependency arrows */

test('the arrows follow a bar while it is dragged, and the one carried to another row', () => {
    const c = groupedChart({ bars: [bar({ id: '1', group: '1', regroupable: true }), bar({ id: '2', group: '2', start: '2026-10-10', end: '2026-10-12', regroupable: true, deps: ['1'] })] })
    // rows: Acme (0), bar 1 (36), Beta (72), bar 2 (108); 48px a day from Oct 1
    assert.equal(c.arrowPaths[0].d, 'M336,54 H344 V126 H432 m-6,-4 l6,4 l-6,4')
    assert.equal(c.bodyHeight, 144)

    const p = press(c, rowBar(c, '1'), { y: 50 })
    p.move(48)
    assert.equal(c.arrowPaths[0].d, 'M384,54 H392 V126 H432 m-6,-4 l6,4 l-6,4')
    // up to Acme's own group row: the bar is drawn 36px higher, its arrow starts there
    c.drag({ pointerId: 7, clientX: 148, clientY: 10 })
    assert.equal(c.target, null, 'its own group')
    c.drag({ pointerId: 7, clientX: 148, clientY: 125 })
    assert.equal(c.arrowPaths[0].d, 'M384,126 H392 V126 H432 m-6,-4 l6,4 l-6,4')
    p.up(48)
    assert.equal(c.arrowPaths[0].key, '1>2')
    // dropped in Beta a day later: while pending the bar keeps both its new row and its new dates
    // (rows: Acme (0), Beta (36), bar 1 (72), bar 2 (108)); the two SVG paths hold every arrow of their kind
    assert.deepEqual(c.wire.moves, [['1', '2026-10-06 00:00', '2026-10-09 00:00', '2']])
    assert.equal(c.arrowD(false), 'M384,90 H392 V126 H432 m-6,-4 l6,4 l-6,4')
    assert.equal(c.arrowD(true), '')
})

test('↑/↓ into a folded group unfolds it, keeps the focus and sends the move', () => fakeTimers((timers) => {
    const c = groupedChart({ groups: [{ key: '1', label: 'Acme', color: null, collapsed: false, count: 1 }, { key: '2', label: 'Beta', color: null, collapsed: true, count: 1 }] })
    c.persist = () => {}
    c.init = () => {}
    c.collapsed = { 1: false, 2: true }
    c.rebuild()
    assert.equal(rowBar(c, '2'), null, 'Beta is folded')

    key(c, rowBar(c, '1'), 'ArrowDown')
    assert.equal(c.collapsed[2], false)
    assert.equal(c.view.rows.find((r) => r.key === 'b:1').group.key, '2')
    timers.filter((t) => t.fn).at(-1).fn()
    assert.deepEqual(c.wire.moves, [['1', '2026-10-05 00:00', '2026-10-08 00:00', '2']])

    // folded again while the keys rest: the move is still sent, from the source bar
    const d = groupedChart()
    d.persist = () => {}
    key(d, rowBar(d, '1'), 'ArrowDown')
    d.collapsed[2] = true
    d.rebuild()
    assert.equal(rowBar(d, '1'), null)
    timers.filter((t) => t.fn).at(-1).fn()
    assert.deepEqual(d.wire.moves, [['1', '2026-10-05 00:00', '2026-10-08 00:00', '2']])
}))

test('a bar with fixed dates can still be carried to another row with the pointer: only its row changes', () => {
    const c = groupedChart({ bars: [bar({ id: '1', group: '1', movable: false, regroupable: true }), bar({ id: '2', group: '2', movable: false, regroupable: true })] })
    const p = press(c, rowBar(c, '1'), { y: 50 })
    c.drag({ pointerId: 7, clientX: 196, clientY: 125 })
    assert.equal(c.target, '2')
    assert.equal(rowBar(c, '1').s, Date.UTC(2026, 9, 5), 'the dates stay')
    p.up(96)
    assert.deepEqual(c.wire.moves, [['1', '2026-10-05 00:00', '2026-10-08 00:00', '2']])

    // released on its own row: nothing to send, and no click either
    const q = press(c, rowBar(c, '2'), { y: 125 })
    c.drag({ pointerId: 7, clientX: 196, clientY: 125 })
    q.up(96)
    assert.equal(c.wire.moves.length, 1)
    assert.deepEqual(c.wire.mounted, [])
})

test('a keyboard move of row and dates keeps both on the source bar while pending, so a rebuild mid-flight changes nothing', () => fakeTimers((timers) => {
    const c = groupedChart()
    key(c, rowBar(c, '1'), 'ArrowRight')
    key(c, rowBar(c, '1'), 'ArrowDown')
    timers.filter((t) => t.fn).at(-1).fn()
    assert.deepEqual(c.wire.moves, [['1', '2026-10-06 00:00', '2026-10-09 00:00', '2']])

    c.rebuild()
    assert.equal(rowBar(c, '1').s, Date.UTC(2026, 9, 6))
    assert.equal(rowBar(c, '1').group, '2')
}))

/* ------------------------------------------------------------------ the date pickers */

test('only a range the user typed is asked for exactly; a refresh keeps it exact while it is theirs', () => {
    const c = chart({ bars: [] })
    c.range = { start: '2026-10-08', end: '2026-10-08' }

    c.applyWindow()
    assert.equal(c.wire.refreshes.at(-1)[4], false)
    c.refresh()
    assert.equal(c.wire.refreshes.at(-1)[4], false)

    c.applyWindow(null, { user: true })
    assert.equal(c.wire.refreshes.at(-1)[4], true)
    c.refresh()
    assert.equal(c.wire.refreshes.at(-1)[4], true)
})

test('a day picked in the date picker sets the window edge as a date and loads it; a filter pick refreshes', () => {
    const c = chart({ filters: [{ name: 'starts_on', type: 'dateRange' }], bars: [] })
    c.init = () => {}
    c.active = { starts_on: { from: '', until: '' } }

    c.pickRange('start', '2026-10-05 00:00:00')
    assert.deepEqual(c.range, { start: '2026-10-05', end: '2026-10-31' })
    assert.equal(c.userRange, true)
    assert.deepEqual(c.wire.refreshes.at(-1), [{}, '2026-10-05', '2026-10-31', false, true])

    c.pickRange('end', null)
    assert.equal(c.range.end, '')

    c.pickFilter('starts_on', 'from', '2026-10-07 00:00:00')
    c.pickFilter('starts_on', 'until', null)
    assert.deepEqual(c.active.starts_on, { from: '2026-10-07', until: '' })
    assert.equal(c.wire.refreshes.at(-1)[0].starts_on.from, '2026-10-07')
})

test('an option chosen in a select filter refreshes once; the URL restores any value of a searchable one', () => {
    const location = globalThis.window.location
    globalThis.window.location = { href: '', search: '?f.assignee_id=42&f.status=open' }
    try {
        const c = chart({ bars: [], filters: [
            { name: 'assignee_id', type: 'select', searchable: true, options: [] },
            { name: 'status', type: 'select', searchable: false, options: [{ value: 'done', label: 'Done' }] },
        ] })
        c.persist = () => {}
        c.init()
        // the searchable filter takes the URL's 42 (it has no list to check against); the plain one ignores 'open'
        assert.equal(c.active.assignee_id, '42')
        assert.equal(c.active.status, '')

        const before = c.wire.refreshes.length
        c.pickSelect('status', 'done')
        assert.equal(c.active.status, 'done')
        assert.equal(c.wire.refreshes.length, before + 1)
        c.pickSelect('status', 'done')
        assert.equal(c.wire.refreshes.length, before + 1, 'the same value again sends nothing')
        c.pickSelect('status', null)
        assert.equal(c.active.status, '')
        assert.equal(c.wire.refreshes.length, before + 2)
    } finally {
        globalThis.window.location = location
    }
})

/* ------------------------------------------------------------------ zoom */

test('Ctrl + wheel zooms around the pointer: the day under it stays put, and the factor crosses levels at its bounds', () => {
    const c = chart({ scales: ['hour', 'day', 'week', 'month', 'year'], scale: 'week', bars: [bar()] })
    c.persist = () => {}
    const el = { scrollLeft: 180, clientWidth: 600, scrollTop: 0, clientHeight: 600, getBoundingClientRect: () => ({ left: 100, right: 700, top: 0, bottom: 600 }), querySelector: () => ({ offsetWidth: 100 }) }
    c.$refs.scroll = el
    // the pointer 100px into the canvas: day (180 + 100) / 18 = 15.56 under it
    c.onWheel({ ctrlKey: true, deltaY: -300, clientX: 300, preventDefault() {} })
    assert.equal(c.scale, 'week')
    assert.ok(c.zoom > 1.5 && c.zoom < 1.6, String(c.zoom))
    assert.equal(Math.round(el.scrollLeft), Math.round(15.556 * c.view.pxPerDay - 100))

    // zooming in past 2× the week level becomes the day level with a smaller factor
    c.zoomTo(40, 300)
    assert.equal(c.scale, 'day')
    assert.ok(Math.abs(c.zoom - 40 / 48) < 0.001)
    // the hour level is 20× the day level, further than the factor's range reaches: past 2× day it is still reached, at its floor
    c.zoomTo(100, 300)
    assert.equal(c.scale, 'hour')
    assert.equal(c.zoom, 0.5)
    // and out from there lands on the day level at its ceiling
    c.zoomTo(400, 300)
    assert.equal(c.scale, 'day')
    assert.equal(c.zoom, 2)
    // and out past 0.5× becomes the coarser level; below year's floor it clamps
    c.zoomTo(1, 300)
    assert.equal(c.scale, 'year')
    assert.equal(c.zoom, 1 / 1.5)
    c.zoomTo(0.1, 300)
    assert.equal(c.zoom, 0.5)

    // a range typed earlier pinned the window; zooming out lets it fill the width again
    c.range = { start: '2026-10-01', end: '2026-10-14' }
    c.applyWindow(null, { user: true })
    c.apply({ window: { start: '2026-10-01', end: '2026-10-14' }, groups: [], bars: [], total: 0 })
    assert.equal(c.userRange, true)
    c.filling = false // an earlier fill's fake request never answered
    const loads = c.wire.refreshes.length
    c.zoomTo(10, 300)
    assert.equal(c.userRange, false)
    assert.ok(c.wire.refreshes.length > loads, 'the fill loads a window as wide as the chart')

    // a plain wheel is left to the browser; a level chosen by hand is shown as designed
    c.onWheel({ ctrlKey: false, deltaY: -300, clientX: 300, preventDefault() { throw new Error('prevented') } })
    c.setScale('month')
    assert.equal(c.zoom, 1)
    assert.equal(c.view.pxPerDay, 5)
})

test('a pinch zooms by the fingers\' distance; one finger does nothing', () => {
    const c = chart({ scale: 'week', bars: [bar()] })
    c.persist = () => {}
    c.$refs.scroll = { scrollLeft: 0, clientWidth: 600, getBoundingClientRect: () => ({ left: 0, right: 600, top: 0, bottom: 600 }), querySelector: () => ({ offsetWidth: 0 }) }
    c.pinchStart({ touches: [{ clientX: 100, clientY: 100 }] })
    assert.equal(c.pinch, null)
    c.pinchStart({ touches: [{ clientX: 100, clientY: 100 }, { clientX: 200, clientY: 100 }] })
    c.pinchMove({ touches: [{ clientX: 50, clientY: 100 }, { clientX: 250, clientY: 100 }], cancelable: true, preventDefault() {} })
    assert.equal(c.zoom, 2)
    c.pinchEnd()
    assert.equal(c.pinch, null)
})

test('Fit loads the span of the filtered bars and draws it at the width of the chart', async () => {
    const c = chart({ scale: 'day', bars: [bar()] })
    c.persist = () => {}
    c.$refs.scroll = { scrollLeft: 0, clientWidth: 1100, querySelector: () => ({ offsetWidth: 200 }), getBoundingClientRect: () => ({ left: 0, right: 1100, top: 0, bottom: 600 }) }
    c.$wire.ganttSpan = async (filters) => { c.wire.spans = [filters]; return { start: '2026-07-01', end: '2026-12-31' } }
    c.$wire.ganttRefresh = async (filters, from, until) => { c.wire.refreshes.push([filters, from, until]); return { window: { start: from, end: until }, groups: [], bars: [], total: 0 } }

    await c.fitAll()
    assert.deepEqual(c.wire.spans, [{}])
    // 184 days in 900px: 4.9px a day, the month level at nearly 1×
    assert.deepEqual(c.period, { start: '2026-07-01', end: '2026-12-31' })
    assert.equal(c.scale, 'month')
    assert.ok(Math.abs(c.view.pxPerDay - 900 / 184) < 0.01, String(c.view.pxPerDay))

    // a long label beside a short bar at the very end: fewer pixels a day, so the label ends inside the chart,
    // and the window then grows to the width (one more load)
    const label = 'Beta release to TestFlight, then the App Store'
    c.$wire.ganttRefresh = async (filters, from, until) => { c.wire.refreshes.push([filters, from, until]); return { window: { start: from, end: until }, groups: [], bars: [bar({ id: '9', start: '2026-12-30', end: '2026-12-31', label })], total: 1 } }
    const loads = c.wire.refreshes.length
    await c.fitAll()
    const labelPx = c.textWidth(label) + 18
    // the bar's chart end is Dec 31 00:00, 183 days from the start
    assert.ok(Math.abs(c.view.pxPerDay - (900 - labelPx) / 183) < 0.01, String(c.view.pxPerDay))
    assert.equal(c.userRange, false)
    assert.ok(c.wire.refreshes.length > loads + 1, 'the fill loads the wider window')
    assert.ok(parse(c.range.end) > parse('2026-12-31'))

    // nothing to fit: nothing changes
    c.$wire.ganttSpan = async () => null
    await c.fitAll()
    assert.equal(c.scale, 'month')
})

test('the zoom factor lives in the URL and local storage, clamped', () => {
    const location = globalThis.window.location
    const saved = []
    globalThis.window.location = { href: 'https://x.test/?scale=week&zoom=9', search: '?scale=week&zoom=9' }
    globalThis.window.history.replaceState = (s, t, url) => saved.push(String(url))
    try {
        const c = chart({ bars: [] })
        c.init()
        assert.equal(c.zoom, 2)
        c.zoom = 1.25
        c.persist()
        assert.ok(saved.at(-1).includes('zoom=1.25'))
        c.zoom = 1
        c.persist()
        assert.ok(! saved.at(-1).includes('zoom='))
    } finally {
        globalThis.window.location = location
    }
})
