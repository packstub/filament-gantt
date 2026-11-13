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
const day = (d) => Date.UTC(2026, 9, d)

const groups = [{ key: '1', label: 'Acme', color: 'amber', collapsed: false, count: 3 }, { key: '2', label: 'Beta', color: null, collapsed: false, count: 1 }]

/** Bo listed in every group, Cy listed in Acme only, Di met on a bar of Beta. */
const rows = [
    { key: 'bo', label: 'Bo', color: 'sky', group: null, listed: true },
    { key: 'cy', label: 'Cy', color: null, group: '1', listed: true },
    { key: 'di', label: 'Di', color: null, group: null, listed: false },
]

/**
 * Rows (36px a lane): Acme (0), Bo in Acme with two lanes (36–108), Cy (108), the
 * unassigned row (144), Beta (180), Bo in Beta, empty (216), Di (252).
 */
function rowChart(over = {}) {
    const c = chart({
        grouped: true,
        rowLayout: true,
        groupMoves: true,
        unassignedLabel: 'Unassigned',
        groups,
        rows,
        bars: [
            bar({ id: '1', group: '1', row: 'bo', label: 'Design', start: '2026-10-05', end: '2026-10-08', regroupable: true }),
            bar({ id: '2', group: '1', row: 'bo', label: 'Build', start: '2026-10-06', end: '2026-10-09', regroupable: true }),
            bar({ id: '3', group: '1', row: '', label: 'Loose', start: '2026-10-06', end: '2026-10-08', regroupable: true }),
            bar({ id: '4', group: '2', row: 'di', label: 'Beta task', start: '2026-10-12', end: '2026-10-14', regroupable: true }),
        ],
        createAction: { name: 'create' },
        ...over,
    })
    c.$refs.body = { getBoundingClientRect: () => ({ top: 0 }) }
    return c
}

/* ------------------------------------------------------------------ layout */

test('a row holds its bars side by side, stacked in lanes when they overlap, under each group it belongs to', () => {
    const c = rowChart()

    assert.deepEqual(c.view.rows.map((r) => [r.type, r.label, r.height, r.bars.map((b) => b.id).join(',')]), [
        ['group', 'Acme', 36, ''],
        ['row', 'Bo', 72, '1,2'],
        ['row', 'Cy', 36, ''],
        ['row', 'Unassigned', 36, '3'],
        ['group', 'Beta', 36, ''],
        ['row', 'Bo', 36, ''],
        ['row', 'Di', 36, '4'],
    ])
    assert.deepEqual(c.view.rows[1].bars.map((b) => b.lane), [0, 1])
    assert.deepEqual(c.view.rows.map((r) => r.row), [undefined, 'bo', 'cy', '', undefined, 'bo', 'di'])
    // two groups, one row key: two rows the chart tells apart
    assert.notEqual(c.view.rows[1].key, c.view.rows[5].key)
})

test('the bars without a row share the unassigned row; unassignedRow() keeps it in every group, empty or not', () => {
    const window = {
        start: '2026-10-01', end: '2026-10-31', today: '2026-10-14', pxPerDay: 48, rowHeight: 36, grouped: true, rowLayout: true,
        unassignedLabel: 'No machine',
        groups: [{ key: 'a', label: 'A' }, { key: 'b', label: 'B' }],
        rows: [{ key: 'x', label: 'X', group: null, listed: true }],
    }
    const bars = [
        bar({ id: '1', group: 'a', row: '', start: '2026-10-05', end: '2026-10-08' }),
        bar({ id: '2', group: 'a', row: '', start: '2026-10-06', end: '2026-10-09' }),
        bar({ id: '3', group: 'a', row: '', start: '2026-10-12', end: '2026-10-13' }),
    ]

    let view = layout(bars, window, 'day')
    assert.deepEqual(view.rows.map((r) => [r.label, r.row, r.height]), [['A', undefined, 36], ['X', 'x', 36], ['No machine', '', 72], ['B', undefined, 36], ['X', 'x', 36]])
    assert.deepEqual(view.rows[2].bars.map((b) => b.lane), [0, 1, 0])

    view = layout(bars, { ...window, unassignedRow: true }, 'day')
    assert.deepEqual(view.rows.map((r) => r.label), ['A', 'X', 'No machine', 'B', 'X', 'No machine'])
    assert.equal(new Set(view.rows.map((r) => r.key)).size, view.rows.length)
})

test('a met row shows only in the groups of its bars, a listed one in its group or in all', () => {
    const view = layout([bar({ id: '1', group: 'a:b', row: 'x' })], {
        start: '2026-10-01', end: '2026-10-31', today: '2026-10-14', pxPerDay: 48, rowHeight: 36, grouped: true, rowLayout: true,
        groups: [{ key: 'a', label: 'A' }, { key: 'a:b', label: 'A:B' }],
        rows: [{ key: 'b:x', label: 'Listed', group: null, listed: true }, { key: 'x', label: 'X', group: null, listed: false }],
    }, 'day')

    assert.deepEqual(view.rows.map((r) => r.label), ['A', 'Listed', 'A:B', 'Listed', 'X'])
    // a group key with a colon never makes two rows share a key
    assert.equal(new Set(view.rows.map((r) => r.key)).size, view.rows.length)
})

test('the label column shows a row\'s own colour, and the count only on a group\'s row', () => {
    const c = rowChart()

    assert.deepEqual(c.view.rows.map((r) => c.labelColor(r)), ['amber', 'sky', null, null, null, 'sky', null])
})

/* ------------------------------------------------------------------ dragging to another row */

test('a bar dragged over another row of its group lands there: the row lights up and the move sends it', () => {
    const c = rowChart()
    const p = press(c, rowBar(c, '1'), { y: 50 })

    c.drag({ pointerId: 7, clientX: 100, clientY: 120 })
    assert.equal(c.targetRow, c.view.rows[2].key)
    assert.equal(c.target, null)
    assert.equal(c.barStyle(rowBar(c, '1')).transform, 'translateY(72px)')
    assert.equal(c.hint, 'Oct 5, 2026 – Oct 7, 2026 · Cy')

    p.up()
    assert.deepEqual(c.wire.moves, [['1', '2026-10-05 00:00', '2026-10-08 00:00', null, 'cy']])
    assert.equal(c.bars.find((b) => b.id === '1').row, 'cy')
    assert.deepEqual(c.view.rows[2].bars.map((b) => b.id), ['1'])
    assert.equal(c.targetRow, null)
})

test('the same row in another group changes the group only; a group\'s row is no target, a bar\'s own row neither', () => {
    let c = rowChart()
    let p = press(c, rowBar(c, '1'), { y: 50 })
    c.drag({ pointerId: 7, clientX: 100, clientY: 230 }) // Bo, in Beta
    p.up()
    assert.deepEqual(c.wire.moves, [['1', '2026-10-05 00:00', '2026-10-08 00:00', '2']])

    c = rowChart()
    p = press(c, rowBar(c, '1'), { y: 50 })
    c.drag({ pointerId: 7, clientX: 100, clientY: 190 }) // Beta's own row
    assert.equal(c.targetRow, null)
    c.drag({ pointerId: 7, clientX: 100, clientY: 90 }) // its own row, the other lane
    assert.equal(c.targetRow, null)
    p.up()
    assert.deepEqual(c.wire.moves, [])
})

test('the unassigned row takes a bar off its row, and says so; a bar already there stays', () => {
    let c = rowChart()
    let p = press(c, rowBar(c, '1'), { y: 50 })
    c.drag({ pointerId: 7, clientX: 100, clientY: 150 })
    assert.equal(c.hint, 'Oct 5, 2026 – Oct 7, 2026 · Unassigned')
    p.up()

    assert.deepEqual(c.wire.moves, [['1', '2026-10-05 00:00', '2026-10-08 00:00', null, '']])

    c = rowChart()
    p = press(c, rowBar(c, '3'), { y: 150 })
    c.drag({ pointerId: 7, clientX: 100, clientY: 160 })
    assert.equal(c.targetRow, null)
    p.up()
    assert.deepEqual(c.wire.moves, [])
})

test('with unassignedRow() an empty unassigned row is a target too, in another group as well', () => {
    const c = rowChart({ unassignedRow: true })
    // Beta now ends with its own unassigned row: Beta (180), Bo (216), Di (252), Unassigned (288)
    assert.deepEqual(c.view.rows.slice(4).map((r) => r.label), ['Beta', 'Bo', 'Di', 'Unassigned'])

    const p = press(c, rowBar(c, '1'), { y: 50 })
    c.drag({ pointerId: 7, clientX: 100, clientY: 300 })
    p.up()

    assert.deepEqual(c.wire.moves, [['1', '2026-10-05 00:00', '2026-10-08 00:00', '2', '']])
})

test('rows of another group are no target when the group cannot be written', () => {
    const c = rowChart({ groupMoves: false })
    const p = press(c, rowBar(c, '1'), { y: 50 })
    c.drag({ pointerId: 7, clientX: 100, clientY: 260 }) // Di, in Beta
    assert.equal(c.targetRow, null)
    c.drag({ pointerId: 7, clientX: 100, clientY: 120 }) // Cy, in Acme
    p.up()

    assert.deepEqual(c.wire.moves, [['1', '2026-10-05 00:00', '2026-10-08 00:00', null, 'cy']])
})

test('a bar that may not change rows only moves along its row', () => {
    const c = rowChart()
    c.bars[0].regroupable = false
    c.rebuild()
    const p = press(c, rowBar(c, '1'), { y: 50 })
    c.drag({ pointerId: 7, clientX: 148, clientY: 120 })
    assert.equal(c.targetRow, null)
    p.up(48)

    assert.deepEqual(c.wire.moves, [['1', '2026-10-06 00:00', '2026-10-09 00:00']])
})

test('a refused move puts the bar back on its row', async () => {
    const c = rowChart()
    c.$wire.ganttMove = () => Promise.resolve({ ok: false, message: 'Cy is on leave.' })
    const notices = []
    c.notify = (m) => notices.push(m)

    const p = press(c, rowBar(c, '1'), { y: 50 })
    c.drag({ pointerId: 7, clientX: 100, clientY: 120 })
    p.up()
    assert.equal(c.bars.find((b) => b.id === '1').row, 'cy')
    await tick()

    assert.equal(c.bars.find((b) => b.id === '1').row, 'bo')
    assert.deepEqual(c.view.rows[1].bars.map((b) => b.id), ['1', '2'])
    assert.deepEqual(notices, ['Cy is on leave.'])
})

/* ------------------------------------------------------------------ preventRowOverlap() */

test('a bar stops at the next bar of its row with preventRowOverlap(), and not on another row', () => {
    // Fresh bars for every chart: a move leaves its source bar pending.
    const over = (rule = true) => ({
        preventRowOverlap: rule,
        bars: [
            bar({ id: '1', group: '1', row: 'bo', start: '2026-10-05', end: '2026-10-08', regroupable: true }),
            bar({ id: '2', group: '2', row: 'bo', start: '2026-10-12', end: '2026-10-14', regroupable: true }),
            bar({ id: '3', group: '1', row: 'cy', start: '2026-10-09', end: '2026-10-11', regroupable: true }),
        ],
    })
    let c = rowChart(over())
    let p = press(c, rowBar(c, '1'), { y: 50 })
    p.move(48 * 10)
    // Bo's bar in Beta is Bo's too: the row is the person, in every group
    assert.equal(rowBar(c, '1').s, day(9))
    p.up(48 * 10)
    assert.deepEqual(c.wire.moves, [['1', '2026-10-09 00:00', '2026-10-12 00:00']])

    c = rowChart(over())
    p = press(c, rowBar(c, '1'), { y: 50, edge: 'end' })
    p.move(48 * 10)
    assert.equal(rowBar(c, '1').e, day(12))

    // without the rule, or carried to another row, nothing stops it (the server checks the row it lands on)
    c = rowChart(over(false))
    p = press(c, rowBar(c, '1'), { y: 50 })
    p.move(48 * 10)
    assert.equal(rowBar(c, '1').s, day(15))
})

test('a bar carried onto a bar of another row is told so, and a release there sends nothing', () => {
    const taken = (rule = true) => {
        const c = rowChart({
            preventRowOverlap: rule,
            bars: [
                bar({ id: '1', group: '1', row: 'bo', start: '2026-10-05', end: '2026-10-08', regroupable: true }),
                bar({ id: '3', group: '1', row: 'cy', start: '2026-10-09', end: '2026-10-11', regroupable: true }),
            ],
        })
        c.t.row_overlaps = 'Taken'
        c.notices = []
        c.notify = (m) => c.notices.push(m)
        return c
    }
    // Rows: Acme (0), Bo (36), Cy (72)
    let c = taken()
    press(c, rowBar(c, '1'), { y: 50 })
    c.drag({ pointerId: 7, clientX: 100 + 48 * 4, clientY: 90 })
    assert.equal(c.pointer.blocked, true)
    assert.equal(c.hint, 'Taken')
    // off Cy's bar again (the 6th to the 9th touches it): a free slot of the row
    c.drag({ pointerId: 7, clientX: 100 + 48, clientY: 90 })
    assert.equal(c.pointer.blocked, false)
    c.drag({ pointerId: 7, clientX: 100 + 48 * 4, clientY: 90 })
    c.up({ pointerId: 7, clientX: 100 + 48 * 4, clientY: 90 })
    assert.deepEqual(c.wire.moves, [])
    assert.deepEqual(c.notices, ['Taken'])
    assert.equal(rowBar(c, '1').s, day(5))
    assert.equal(c.bars.find((b) => b.id === '1').row, 'bo')

    // a free slot of that row takes it
    c = taken()
    press(c, rowBar(c, '1'), { y: 50 })
    c.drag({ pointerId: 7, clientX: 100 + 48, clientY: 90 })
    c.up({ pointerId: 7, clientX: 100 + 48, clientY: 90 })
    assert.deepEqual(c.wire.moves, [['1', '2026-10-06 00:00', '2026-10-09 00:00', null, 'cy']])

    // without the rule the bars stack
    c = taken(false)
    press(c, rowBar(c, '1'), { y: 50 })
    c.drag({ pointerId: 7, clientX: 100 + 48 * 4, clientY: 90 })
    assert.equal(c.pointer.blocked, false)
    c.up({ pointerId: 7, clientX: 100 + 48 * 4, clientY: 90 })
    assert.equal(c.wire.moves.length, 1)
})

test('a selection on a row turns red while it overlaps a bar of that row, with preventRowOverlap()', () => {
    const c = rowChart({ preventRowOverlap: true })
    const el = element()
    const row = c.view.rows[1]
    c.selectFrom({ button: 0, pointerId: 9, clientX: 100 + 48 * 9, clientY: 60, pointerType: 'mouse', target: { closest: () => null }, currentTarget: el }, row)

    c.drag({ pointerId: 9, clientX: 100 + 48 * 10, clientY: 60 }) // Oct 10–11: free
    assert.equal(c.pointer.overlaps, false)
    c.drag({ pointerId: 9, clientX: 100 + 48 * 6, clientY: 60 }) // back to Oct 7: over Design and Build
    assert.equal(c.pointer.overlaps, true)
    c.drag({ pointerId: 9, clientX: 100 + 48 * 10, clientY: 60 })
    c.up({ pointerId: 9, clientX: 100 + 48 * 10, clientY: 60 })

    assert.deepEqual(c.wire.mounted, [['create', { ganttStart: '2026-10-10 00:00', ganttEnd: '2026-10-12 00:00', ganttGroup: '1', ganttRow: 'bo' }]])
})

/* ------------------------------------------------------------------ the keys */

test('↑/↓ walk the rows in the chart\'s order, across groups, then send one move; Escape brings the bar back', () => fakeTimers((timers) => {
    const c = rowChart()
    const focused = []
    c.$root.querySelector = (selector) => ({ focus: () => focused.push(selector) })
    document.activeElement = { dataset: { id: '1' } }

    key(c, rowBar(c, '1'), 'ArrowDown')
    assert.equal(c.bars[0].row, 'cy')
    assert.equal(c.hint, 'Oct 5, 2026 – Oct 7, 2026 · Cy')
    key(c, rowBar(c, '1'), 'ArrowDown')
    assert.deepEqual([c.bars[0].group, c.bars[0].row], ['1', ''])
    key(c, rowBar(c, '1'), 'ArrowDown')
    assert.deepEqual([c.bars[0].group, c.bars[0].row], ['2', 'bo'])
    assert.equal(focused.length, 3)

    c.onEscape()
    assert.deepEqual([c.bars[0].group, c.bars[0].row], ['1', 'bo'])
    assert.deepEqual(c.wire.moves, [])

    key(c, rowBar(c, '1'), 'ArrowDown')
    key(c, rowBar(c, '1'), 'ArrowRight')
    fire(timers)
    assert.deepEqual(c.wire.moves, [['1', '2026-10-06 00:00', '2026-10-09 00:00', null, 'cy']])
    document.activeElement = null
}))

test('↑ on the first row and ↓ on the last stay; a folded group opens when a bar gets there', () => fakeTimers(() => {
    const c = rowChart()
    c.collapsed['2'] = true
    c.rebuild()

    key(c, rowBar(c, '1'), 'ArrowUp')
    assert.equal(c.bars[0].row, 'bo')

    key(c, rowBar(c, '1'), 'ArrowDown')
    key(c, rowBar(c, '1'), 'ArrowDown')
    key(c, rowBar(c, '1'), 'ArrowDown')
    assert.deepEqual([c.bars[0].group, c.bars[0].row], ['2', 'bo'])
    assert.equal(c.collapsed['2'], false)

    key(c, rowBar(c, '1'), 'ArrowDown')
    key(c, rowBar(c, '1'), 'ArrowDown')
    assert.deepEqual([c.bars[0].group, c.bars[0].row], ['2', 'di'])
}))

test('a bar that was alone on a row met on the bars can come back to it with the keys', () => fakeTimers(() => {
    const c = rowChart()

    // Di is on the chart only because of bar 4
    key(c, rowBar(c, '4'), 'ArrowUp')
    assert.deepEqual([c.bars[3].group, c.bars[3].row], ['2', 'bo'])
    key(c, rowBar(c, '4'), 'ArrowDown')
    assert.deepEqual([c.bars[3].group, c.bars[3].row], ['2', 'di'])
}))

test('↑/↓ stay in the bar\'s group when the group cannot be written', () => fakeTimers(() => {
    const c = rowChart({ groupMoves: false })

    key(c, rowBar(c, '1'), 'ArrowDown')
    key(c, rowBar(c, '1'), 'ArrowDown')
    key(c, rowBar(c, '1'), 'ArrowDown')
    assert.deepEqual([c.bars[0].group, c.bars[0].row], ['1', ''])
}))

/* ------------------------------------------------------------------ creating in a row */

test('a click on a row creates in it, one on the unassigned row without a row, one on a group\'s row in the group alone', () => {
    const c = rowChart()
    const click = (row) => c.createAt({ target: { closest: () => null }, currentTarget: element(), clientX: 150, clientY: 60 }, row)

    click(c.view.rows[2])
    click(c.view.rows[3])
    click(c.view.rows[0])
    click(c.view.rows[5])

    assert.deepEqual(c.wire.mounted.map(([, args]) => [args.ganttGroup, args.ganttRow]), [['1', 'cy'], ['1', ''], ['1', undefined], ['2', 'bo']])
})

test('a chart without rowBy() sends no row', () => {
    const c = chart({ grouped: true, groups, bars: [bar({ id: '1', group: '1', regroupable: true })], createAction: { name: 'create' } })
    c.createAt({ target: { closest: () => null }, currentTarget: element(), clientX: 150, clientY: 60 }, c.view.rows[1])

    assert.equal('ganttRow' in c.wire.mounted[0][1], false)
})
