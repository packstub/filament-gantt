import { test } from 'node:test'
import assert from 'node:assert/strict'
import { chart, bar, rowBar, press, element, layout } from './harness.mjs'

/** setTimeout/clearTimeout recorded instead of run: the last live one fires with `fire(timers)`. */
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
const fire = (timers) => timers.filter((t) => t.fn).at(-1).fn()
const key = (c, b, k, mods = {}) => c.nudge({ key: k, shiftKey: false, altKey: false, ...mods }, b)
const tick = () => new Promise((r) => setTimeout(r, 0))

const actions = [{ name: 'edit', label: 'Edit' }, { name: 'delete', label: 'Delete' }]

/** Launch (10) with its own dates and two children that overlap, Ideas (20) without dates and one child, Empty (30). */
const rows = [
    { key: '10', label: 'Launch', color: 'sky', group: null, listed: true, actions: ['edit', 'delete'] },
    { key: '20', label: 'Ideas', color: null, group: null, listed: true, actions: ['delete'] },
    { key: '30', label: 'Empty', color: null, group: null, listed: true, actions: [] },
]

/** Rows (36px a lane): Launch with two lanes (0–72), Ideas (72), Empty (108). */
function parentChart(over = {}) {
    const c = chart({
        rowLayout: true,
        parentSpan: 'own',
        moveChildren: true,
        rows,
        barActions: actions,
        barAction: 'edit',
        bars: [
            bar({ id: '10', row: '10', parent: true, label: 'Launch', color: 'sky', start: '2026-10-05', end: '2026-10-21', regroupable: false }),
            bar({ id: '11', row: '10', label: 'Design', start: '2026-10-05', end: '2026-10-08', regroupable: true }),
            bar({ id: '12', row: '10', label: 'Build', start: '2026-10-07', end: '2026-10-10', regroupable: true }),
            bar({ id: '21', row: '20', label: 'Sketch', start: '2026-10-12', end: '2026-10-14', regroupable: true }),
        ],
        ...over,
    })
    c.$refs.body = { getBoundingClientRect: () => ({ top: 0 }) }
    return c
}

/** A $wire call that answers once the test says so. */
function answering(c, method) {
    const calls = []
    c.$wire[method] = (...args) => new Promise((resolve) => calls.push({ args, resolve }))
    return calls
}

/* ------------------------------------------------------------------ layout */

test('a parent\'s span comes first in its row, in no lane, as tall as the row its children make', () => {
    const c = parentChart()

    assert.deepEqual(c.view.rows.map((r) => [r.label, r.height, r.bars.map((b) => b.id).join(',')]), [
        ['Launch', 72, '10,11,12'],
        ['Ideas', 36, '21'],
        ['Empty', 36, ''],
    ])
    assert.deepEqual(c.view.rows[0].bars.map((b) => b.lane), [0, 0, 1])
    assert.equal(rowBar(c, '10').lanes, 2)

    const span = c.barStyle(rowBar(c, '10'))
    assert.deepEqual([span.top, span.height, span.width], ['2px', '68px', 16 * 48 + 'px'])
    assert.equal(c.barStyle(rowBar(c, '11')).top, '4px')
    assert.equal(c.barStyle(rowBar(c, '12')).top, '40px')
    // its name is on the row, never beside the span
    assert.equal(c.labelOutside(rowBar(c, '10'), c.view.rows[0]), false)
})

test('a span alone on its row is one lane tall, and a point in time is a thin span, not a diamond', () => {
    const view = layout([bar({ id: '10', row: '10', parent: true, start: '2026-10-05', end: '2026-10-05', milestone: true })], {
        start: '2026-10-01', end: '2026-10-31', today: '2026-10-14', pxPerDay: 48, rowHeight: 36, rowLayout: true, rows: [rows[0]],
    }, 'day')

    assert.deepEqual([view.rows[0].height, view.rows[0].bars[0].lanes], [36, 1])

    const c = parentChart({ bars: [bar({ id: '10', row: '10', parent: true, start: '2026-10-05', end: '2026-10-05', milestone: true })] })
    assert.equal(c.barStyle(rowBar(c, '10')).width, '0px')
    assert.equal('--ps-gantt-row-inner' in c.barStyle(rowBar(c, '10')), false)
})

test('a computed span is no record: neither the group\'s count nor the footer\'s counts it', () => {
    const c = parentChart({
        parentSpan: 'children', grouped: true, groups: [{ key: '', label: 'Ungrouped', count: 1 }], i18n: { ...chart().t, bars: ':count bars' }, total: 1,
        bars: [
            bar({ id: '10', row: '10', parent: true, computed: true, resizable: false, start: '2026-10-05', end: '2026-10-08' }),
            bar({ id: '11', row: '10', start: '2026-10-05', end: '2026-10-08' }),
        ],
    })

    assert.equal(c.view.rows[0].group.count, 1)
    assert.equal(c.barCount, 1)
    assert.equal(c.countText(), '1 bars')
    assert.deepEqual(c.recount().map((g) => g.count), [1])
})

/* ------------------------------------------------------------------ dragging the span */

test('a span dragged whole takes its children along, and sends one move for them all', () => {
    const c = parentChart()
    const p = press(c, rowBar(c, '10'))

    p.move(96)
    assert.deepEqual(['10', '11', '12', '21'].map((id) => c.dates(rowBar(c, id))), ['Oct 7, 2026 – Oct 22, 2026', 'Oct 7, 2026 – Oct 9, 2026', 'Oct 9, 2026 – Oct 11, 2026', 'Oct 12, 2026 – Oct 13, 2026'])

    p.up(96)
    assert.deepEqual(c.wire.parents, [['10', '2026-10-07 00:00']])
    assert.deepEqual(c.wire.moves, [])
    assert.deepEqual(c.bars.map((b) => [b.id, b.start, !! b._pending]), [
        ['10', '2026-10-07 00:00', true], ['11', '2026-10-07 00:00', true], ['12', '2026-10-09 00:00', true], ['21', '2026-10-12', false],
    ])
})

test('the server\'s answer replaces the parent and its children; a refusal puts them all back', async () => {
    const c = parentChart()
    const calls = answering(c, 'ganttMoveParent')

    let p = press(c, rowBar(c, '10'))
    p.move(48)
    p.up(48)
    calls[0].resolve({ ok: true, bars: [
        bar({ id: '10', row: '10', parent: true, start: '2026-10-06 00:00', end: '2026-10-22 00:00' }),
        bar({ id: '11', row: '10', start: '2026-10-06 00:00', end: '2026-10-09 00:00' }),
        bar({ id: '12', row: '10', start: '2026-10-08 00:00', end: '2026-10-11 00:00' }),
        // a child that was outside the window and still is: not drawn
        bar({ id: '13', row: '10', start: '2026-12-02 00:00', end: '2026-12-03 00:00' }),
    ] })
    await tick()

    assert.deepEqual(c.bars.map((b) => [b.id, b.start, !! b._pending]), [
        ['10', '2026-10-06 00:00', false], ['11', '2026-10-06 00:00', false], ['12', '2026-10-08 00:00', false], ['21', '2026-10-12', false],
    ])
    assert.equal(c.pending, 0)

    const notes = []
    c.notify = (message) => notes.push(message)
    p = press(c, rowBar(c, '10'))
    p.move(48)
    p.up(48)
    calls[1].resolve({ ok: false, message: 'Build, under this parent: This bar cannot be moved.' })
    await tick()

    assert.deepEqual(c.bars.map((b) => [b.start, !! b._pending]), [['2026-10-06 00:00', false], ['2026-10-06 00:00', false], ['2026-10-08 00:00', false], ['2026-10-12', false]])
    assert.deepEqual(notes, ['Build, under this parent: This bar cannot be moved.'])
})

test('an edge of the span resizes the parent alone', () => {
    const c = parentChart()
    const p = press(c, rowBar(c, '10'), { edge: 'end' })

    p.move(96)
    assert.equal(c.dates(rowBar(c, '11')), 'Oct 5, 2026 – Oct 7, 2026')
    p.up(96)

    assert.deepEqual(c.wire.moves, [['10', '2026-10-05 00:00', '2026-10-23 00:00']])
    assert.deepEqual(c.wire.parents, [])
})

test('without moveChildrenWithParent() the span moves alone', () => {
    const c = parentChart({ moveChildren: false })
    const p = press(c, rowBar(c, '10'))

    p.move(48)
    assert.equal(c.dates(rowBar(c, '11')), 'Oct 5, 2026 – Oct 7, 2026')
    p.up(48)

    assert.deepEqual(c.wire.moves, [['10', '2026-10-06 00:00', '2026-10-22 00:00']])
    assert.deepEqual(c.wire.parents, [])
})

test('a span stays while one of its children may not move, and is never carried to another row', () => {
    const c = parentChart()
    c.bars[2].movable = false
    c.rebuild()

    let p = press(c, rowBar(c, '10'))
    p.move(96)
    p.up(96)
    assert.deepEqual([c.wire.parents, c.wire.moves], [[], []])
    assert.equal(c.dates(rowBar(c, '10')), 'Oct 5, 2026 – Oct 20, 2026')

    c.bars[2].movable = true
    c.rebuild()
    p = press(c, rowBar(c, '10'), { y: 20 })
    c.drag({ pointerId: 7, clientX: 100, clientY: 90 }) // over Ideas
    assert.equal(c.targetRow, null)
    p.up()
    assert.deepEqual([c.wire.parents, c.wire.moves], [[], []])
})

test('a child still moves on its own, to another parent too, and the span is never in its way', () => {
    const c = parentChart({ preventRowOverlap: true })
    c.bars[2].start = '2026-10-10'
    c.bars[2].end = '2026-10-12'
    c.rebuild()

    // Design is walled by Build (Oct 10), not by the span it sits on
    assert.deepEqual(c.rowWalls(rowBar(c, '11')), { left: -Infinity, right: Date.UTC(2026, 9, 10) })
    assert.equal(c.rowWalls(rowBar(c, '10')), null)
    assert.equal(c.rowTaken('10', '21', Date.UTC(2026, 9, 12), Date.UTC(2026, 9, 14)), false)

    const p = press(c, rowBar(c, '21'), { y: 80 })
    c.drag({ pointerId: 7, clientX: 100, clientY: 10 }) // Launch's row
    assert.equal(c.targetRow, c.view.rows[0].key)
    p.up()

    assert.deepEqual(c.wire.moves, [['21', '2026-10-12 00:00', '2026-10-14 00:00', null, '10']])
})

test('a child that moved under computed spans reloads them, once nothing is pending', async () => {
    const c = parentChart({ parentSpan: 'children' })
    const calls = answering(c, 'ganttMove')
    const p = press(c, rowBar(c, '21'))
    p.move(48)
    p.up(48)
    assert.equal(c.wire.refreshes.length, 0)

    calls[0].resolve({ ok: true, bar: bar({ id: '21', row: '20', start: '2026-10-13 00:00', end: '2026-10-15 00:00' }) })
    await tick()

    assert.equal(c.wire.refreshes.length, 1)
    assert.equal(c.pending, 0)

    // with the parents' own dates there is nothing to recompute
    const own = parentChart()
    const answers = answering(own, 'ganttMove')
    const q = press(own, rowBar(own, '21'))
    q.move(48)
    q.up(48)
    answers[0].resolve({ ok: true, bar: bar({ id: '21', row: '20', start: '2026-10-13 00:00', end: '2026-10-15 00:00' }) })
    await tick()
    assert.equal(own.wire.refreshes.length, 0)
})

/* ------------------------------------------------------------------ the keys */

test('←/→ on a span move it with its children; Shift resizes the parent alone, sent apart', () => fakeTimers((timers) => {
    const c = parentChart()

    key(c, rowBar(c, '10'), 'ArrowRight')
    key(c, rowBar(c, '10'), 'ArrowRight')
    assert.deepEqual(['10', '11', '12'].map((id) => c.dates(rowBar(c, id))), ['Oct 7, 2026 – Oct 22, 2026', 'Oct 7, 2026 – Oct 9, 2026', 'Oct 9, 2026 – Oct 11, 2026'])

    // the edge is another move: the first is sent before it starts
    key(c, rowBar(c, '10'), 'ArrowRight', { shiftKey: true })
    assert.deepEqual(c.wire.parents, [['10', '2026-10-07 00:00']])
}))

test('Escape puts a nudged span and its children back; ↑/↓ say it stays on its row', () => fakeTimers(() => {
    const c = parentChart()
    const said = []
    c.say = (message) => said.push(message)

    key(c, rowBar(c, '10'), 'ArrowLeft')
    assert.equal(c.dates(rowBar(c, '11')), 'Oct 4, 2026 – Oct 6, 2026')
    c.onEscape()
    assert.deepEqual(['10', '11'].map((id) => c.dates(rowBar(c, id))), ['Oct 5, 2026 – Oct 20, 2026', 'Oct 5, 2026 – Oct 7, 2026'])
    assert.deepEqual(c.wire.parents, [])

    c.t.not_regroupable = 'Stays on its row'
    key(c, rowBar(c, '10'), 'ArrowDown')
    assert.equal(said.at(-1), 'Stays on its row')
}))

/* ------------------------------------------------------------------ clicks */

test('a click on a span creates under the parent, at the day under the pointer; where the chart cannot create, it opens the parent', () => {
    const c = parentChart({ createAction: { name: 'create' } })
    // the span's box starts at x = 100: 2.5 days in is 7 October
    press(c, rowBar(c, '10'), { x: 220 }).up()

    assert.deepEqual(c.wire.mounted, [['create', { ganttStart: '2026-10-07 00:00', ganttEnd: '2026-10-08 00:00', ganttGroup: null, ganttRow: '10' }]])

    const plain = parentChart()
    press(plain, rowBar(plain, '10')).up()
    assert.deepEqual(plain.wire.mounted, [['edit', { ganttRecord: '10' }]])

    // a child's click is its own
    const child = parentChart({ createAction: { name: 'create' } })
    press(child, rowBar(child, '11')).up()
    assert.deepEqual(child.wire.mounted, [['edit', { ganttRecord: '11' }]])
})

test('a parent\'s name runs its click action, or opens its menu when it does not offer that one', () => {
    const c = parentChart()
    const [launch, ideas, empty] = c.view.rows

    assert.deepEqual([c.rowActs(launch), c.rowActs(ideas), c.rowActs(empty)], [true, true, false])

    c.openRow(launch, { clientX: 10, clientY: 10, currentTarget: element() })
    assert.deepEqual(c.wire.mounted, [['edit', { ganttRecord: '10' }]])

    // Ideas may only be deleted: a click opens the menu, like a right-click
    c.openRow(ideas, { clientX: 10, clientY: 10, currentTarget: element() })
    assert.equal(c.menu.bar.id, '20')
    assert.deepEqual(c.actionsFor(c.menu.bar).map((a) => a.name), ['delete'])
    c.runAction('delete', c.menu.bar)
    assert.deepEqual(c.wire.mounted.at(-1), ['delete', { ganttRecord: '20' }])
    assert.equal(c.menu, null)

    let prevented = 0
    c.rowMenu(launch, { clientX: 10, clientY: 10, currentTarget: element(), preventDefault: () => prevented++ })
    assert.deepEqual([c.menu.bar.id, prevented], ['10', 1])
    c.closeMenu()

    // a row with nothing to run keeps the browser's own menu, and a click does nothing
    c.rowMenu(empty, { preventDefault: () => prevented++ })
    c.openRow(empty, { clientX: 10, clientY: 10, currentTarget: element() })
    assert.deepEqual([c.menu, prevented, c.wire.mounted.length], [null, 1, 2])
})

test('a row that is no parent has nothing on its name', () => {
    const c = chart({ grouped: true, rowLayout: true, barActions: actions, barAction: 'edit', groups: [{ key: 'a', label: 'A' }], rows: [{ key: 'x', label: 'X', group: null, listed: true }], bars: [bar({ group: 'a', row: 'x' })] })

    assert.deepEqual(c.view.rows.map((r) => c.rowActs(r)), [false, false])
    c.openRow(c.view.rows[1], {})
    assert.deepEqual(c.wire.mounted, [])
})
