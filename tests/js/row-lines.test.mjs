import { test } from 'node:test'
import assert from 'node:assert/strict'
import { chart, bar, rowBar, press, layout } from './harness.mjs'

/** setTimeout/clearTimeout recorded instead of run. */
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

const piece = (id, start, end, over = {}) => ({ id, group: '', row: 'bo', start: start + ' 00:00', end: end + ' 00:00', allDay: true, milestone: false, movable: true, regroupable: true, ...over })

const rows = [{ key: 'bo', label: 'Bo', color: null, group: null, listed: true }, { key: 'cy', label: 'Cy', color: null, group: null, listed: true }]

/**
 * Bo's row holds the Design line (5–7 and 12–13 Oct) and Other (6–8 Oct), which overlaps
 * its first piece: two lanes (0–72). Cy's row is empty (72).
 */
function rowLineChart(over = {}) {
    const c = chart({
        rowLayout: true,
        lineLayout: true,
        rows,
        bars: [
            bar({ id: '1', row: 'bo', line: '1', label: 'Design', start: '2026-10-05', end: '2026-10-08', regroupable: true }),
            bar({ id: '4', row: 'bo', line: '4', label: 'Other', start: '2026-10-06', end: '2026-10-09', regroupable: true }),
            bar({ id: '2', row: 'bo', line: '1', label: 'Design 2', start: '2026-10-12', end: '2026-10-14', regroupable: true }),
        ],
        lines: [{ key: '1', label: 'Design', pieces: [piece('1', '2026-10-05', '2026-10-08'), piece('2', '2026-10-12', '2026-10-14')] }],
        ...over,
    })
    c.$refs.body = { getBoundingClientRect: () => ({ top: 0 }) }
    return c
}

test('the pieces of a line share a lane on their row, and a joiner runs between them', () => {
    const c = rowLineChart()

    assert.deepEqual(c.view.rows.map((r) => [r.type, r.label, r.height]), [['row', 'Bo', 72], ['row', 'Cy', 36]])
    // the line takes one lane from its first piece to its last; Other, which overlaps it, the next
    assert.deepEqual(c.view.rows[0].bars.map((b) => [b.id, b.lane]), [['1', 0], ['4', 1], ['2', 0]])
    assert.deepEqual(c.gaps(c.view.rows[0]), [{ key: 'gap:2', x: 336, w: 192, lane: 0, line: '1' }])
    assert.equal(c.gapStyle(c.gaps(c.view.rows[0])[0], c.view.rows[0]).top, '18px')
    assert.deepEqual(c.gaps(c.view.rows[1]), [])
})

test('pieces that overlap each other take the lanes they find, and get no joiner across lanes', () => {
    const view = layout([
        bar({ id: '1', row: 'bo', line: '1', start: '2026-10-05', end: '2026-10-08' }),
        bar({ id: '2', row: 'bo', line: '1', start: '2026-10-07', end: '2026-10-10' }),
    ], { start: '2026-10-01', end: '2026-10-31', today: '2026-10-14', pxPerDay: 48, rowHeight: 36, rowLayout: true, lineLayout: true, rows }, 'day')

    assert.deepEqual(view.rows[0].bars.map((b) => b.lane), [0, 1])

    // without lineBy() a row packs its bars as before
    const plain = layout([
        bar({ id: '1', row: 'bo', start: '2026-10-05', end: '2026-10-08' }),
        bar({ id: '2', row: 'bo', start: '2026-10-12', end: '2026-10-14' }),
        bar({ id: '4', row: 'bo', start: '2026-10-09', end: '2026-10-11' }),
    ], { start: '2026-10-01', end: '2026-10-31', today: '2026-10-14', pxPerDay: 48, rowHeight: 36, rowLayout: true, rows }, 'day')
    assert.deepEqual(plain.rows[0].bars.map((b) => b.lane), [0, 0, 0])
})

test('the first piece of a line carries the line along its row', () => {
    const c = rowLineChart()
    const p = press(c, rowBar(c, '1'), { y: 20 })

    p.move(48)
    assert.deepEqual(['1', '2', '4'].map((id) => c.dates(rowBar(c, id))), ['Oct 6, 2026 – Oct 8, 2026', 'Oct 13, 2026 – Oct 14, 2026', 'Oct 6, 2026 – Oct 8, 2026'])
    p.up(48)

    assert.deepEqual(c.wire.lines, [['1', '2026-10-06 00:00']])
    assert.deepEqual(c.wire.moves, [])
})

test('any piece carried to another row carries its line there, with the row in the move', () => {
    const c = rowLineChart()
    const p = press(c, rowBar(c, '2'), { y: 20 })

    c.drag({ pointerId: 7, clientX: 100, clientY: 90 }) // Cy's row
    assert.equal(c.targetRow, c.view.rows[1].key)
    assert.equal(c.carried(rowBar(c, '1')), true)
    assert.equal(c.carried(rowBar(c, '4')), false)
    c.up({ pointerId: 7, clientX: 100, clientY: 90 })

    assert.deepEqual(c.wire.lines, [['2', '2026-10-12 00:00', null, 'cy']])
    assert.deepEqual(c.bars.map((b) => [b.id, b.row]), [['1', 'cy'], ['4', 'bo'], ['2', 'cy']])
    assert.deepEqual(c.lineIndex.get('1').pieces.map((piece) => piece.row), ['cy', 'cy'])
    assert.deepEqual(c.view.rows.map((r) => r.bars.map((b) => b.id).join(',')), ['4', '1,2'])
})

test('a refused carry puts every piece back on its row', async () => {
    const c = rowLineChart()
    let answer
    c.$wire.ganttMoveLine = () => new Promise((resolve) => { answer = resolve })
    c.notify = () => {}

    press(c, rowBar(c, '2'), { y: 20 })
    c.drag({ pointerId: 7, clientX: 100, clientY: 90 })
    c.up({ pointerId: 7, clientX: 100, clientY: 90 })
    answer({ ok: false, message: 'No.' })
    await new Promise((r) => setTimeout(r, 0))

    assert.deepEqual(c.bars.map((b) => [b.id, b.row, !! b._pending]), [['1', 'bo', false], ['4', 'bo', false], ['2', 'bo', false]])
    assert.deepEqual(c.lineIndex.get('1').pieces.map((piece) => piece.row), ['bo', 'bo'])
})

test('a piece that is not the first moves alone, and stops at the next bar of its row and the next piece of its line', () => {
    const c = rowLineChart({ preventRowOverlap: true, preventLineOverlap: true })

    // Design 2 (12–13 Oct): Other ends on the 9th, the first piece on the 8th: the nearer one is the wall
    assert.deepEqual(c.barWalls(c.lineOf(rowBar(c, '2')), rowBar(c, '2'), false), { left: Date.UTC(2026, 9, 9), right: Infinity })
    // the first piece carries its line: the pieces that follow it are not its walls
    assert.equal(c.barWalls(c.lineOf(rowBar(c, '1')), rowBar(c, '1'), true), null)

    const p = press(c, rowBar(c, '2'), { y: 20 })
    p.move(-48 * 6)
    assert.equal(c.dates(rowBar(c, '2')), 'Oct 9, 2026 – Oct 10, 2026')
    p.up(-48 * 6)
    assert.deepEqual(c.wire.moves, [['2', '2026-10-09 00:00', '2026-10-11 00:00']])
    assert.deepEqual(c.wire.lines, [])
})

test('↑/↓ carry a line to the next row and back; Escape puts every piece where it was', () => fakeTimers((timers) => {
    const c = rowLineChart()
    c.$root.querySelector = () => ({ focus() {} })

    key(c, rowBar(c, '2'), 'ArrowDown')
    assert.deepEqual(c.bars.map((b) => [b.id, b.row]), [['1', 'cy'], ['4', 'bo'], ['2', 'cy']])
    c.onEscape()
    assert.deepEqual(c.bars.map((b) => [b.id, b.row]), [['1', 'bo'], ['4', 'bo'], ['2', 'bo']])

    key(c, rowBar(c, '2'), 'ArrowDown')
    fire(timers)
    assert.deepEqual(c.wire.lines, [['2', '2026-10-12 00:00', null, 'cy']])
}))
