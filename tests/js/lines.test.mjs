import { test } from 'node:test'
import assert from 'node:assert/strict'
import { chart, bar, rowBar, press, element, layout, arrows, parse, DAY } from './harness.mjs'

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

/** A piece of a line, as the server sends it in `lines`. */
const piece = (id, start, end, over = {}) => ({ id, group: '1', start: start + ' 00:00', end: end + ' 00:00', allDay: true, milestone: false, movable: true, regroupable: true, ...over })

/** Design is split in three: Oct 5–7 and Oct 12–13 in the window (October), Dec 1–2 outside it. */
const design = (over = {}) => ({
    key: '1',
    label: 'Design',
    pieces: [piece('1', '2026-10-05', '2026-10-08'), piece('2', '2026-10-12', '2026-10-14'), piece('3', '2026-12-01', '2026-12-03')],
    ...over,
})

/**
 * Rows (36px each): Acme (0), the Design line with pieces 1 and 2 (36), Other (72),
 * Beta (108), Beta task (144). At 48px a day, a piece's x is its day in October × 48 − 48.
 */
function lineChart(over = {}) {
    const c = chart({
        grouped: true,
        lineLayout: true,
        groups: [{ key: '1', label: 'Acme', color: null, collapsed: false, count: 3 }, { key: '2', label: 'Beta', color: null, collapsed: false, count: 1 }],
        bars: [
            bar({ id: '1', group: '1', line: '1', label: 'Design', start: '2026-10-05', end: '2026-10-08', regroupable: true }),
            bar({ id: '4', group: '1', line: '4', label: 'Other', start: '2026-10-06', end: '2026-10-08', regroupable: true }),
            bar({ id: '2', group: '1', line: '1', label: 'Design 2', start: '2026-10-12', end: '2026-10-14', regroupable: true }),
            bar({ id: '5', group: '2', line: '5', label: 'Beta task', start: '2026-10-05', end: '2026-10-07', regroupable: true }),
        ],
        lines: [design()],
        createAction: { name: 'create' },
        ...over,
    })
    c.$refs.body = { getBoundingClientRect: () => ({ top: 0 }) }
    return c
}

const day = (d) => Date.UTC(2026, 9, d)

/* ------------------------------------------------------------------ layout */

test('the pieces of a line share a row, in the order of its first bar, named after the line', () => {
    const c = lineChart()

    assert.deepEqual(c.view.rows.map((r) => r.key), ['g:1', 'l:1:1', 'b:4', 'g:2', 'b:5'])
    const row = c.view.rows[1]
    assert.equal(row.type, 'bar')
    assert.equal(row.label, 'Design')
    assert.equal(row.line, '1')
    assert.equal(row.height, 36)
    assert.deepEqual(row.bars.map((b) => b.id), ['1', '2'])
    // a record alone on its line keeps its own row, and its line (a create there adds a piece)
    assert.equal(c.view.rows[2].line, '4')
    assert.equal(c.view.rows[2].label, 'Other')
})

test('overlapping pieces stack in lanes and the row grows; a line seen through one piece keeps its name', () => {
    const lines = new Map([['1', { key: '1', label: 'Design', pieces: [] }]])
    const window = { start: '2026-10-01', end: '2026-10-31', pxPerDay: 48, rowHeight: 36, groups: [], grouped: false, lineLayout: true, lines, collapsed: {} }

    let view = layout([
        bar({ id: '1', line: '1', start: '2026-10-05', end: '2026-10-08' }),
        bar({ id: '2', line: '1', start: '2026-10-07', end: '2026-10-09' }),
    ], window, 'day')
    assert.equal(view.rows.length, 1)
    assert.equal(view.rows[0].height, 72)
    assert.deepEqual(view.rows[0].bars.map((b) => b.lane), [0, 1])

    // pieces that touch end to start are one stretch of work: one lane
    view = layout([
        bar({ id: '1', line: '1', start: '2026-10-05', end: '2026-10-08' }),
        bar({ id: '2', line: '1', start: '2026-10-08', end: '2026-10-09' }),
    ], window, 'day')
    assert.equal(view.rows[0].height, 36)

    view = layout([bar({ id: '2', line: '1', label: 'Design 2' })], window, 'day')
    assert.equal(view.rows[0].key, 'l::1')
    assert.equal(view.rows[0].label, 'Design')
})

test('without the line layout (no lineBy(), or compact) the rows are what they were', () => {
    const bars = [bar({ id: '1', line: '1' }), bar({ id: '2', line: '1', start: '2026-10-12', end: '2026-10-13' })]
    const window = { start: '2026-10-01', end: '2026-10-31', pxPerDay: 48, rowHeight: 36, groups: [], grouped: false, collapsed: {} }

    assert.deepEqual(layout(bars, window, 'day').rows.map((r) => r.key), ['b:1', 'b:2'])
    assert.deepEqual(layout(bars, { ...window, compact: true }, 'day').rows.map((r) => r.type), ['lanes'])

    const c = lineChart({ compact: true })
    assert.equal(c.carriesLine(rowBar(c, '1')), false)
})

test('an arrow between two pieces of one line turns back under their row, never along the bars', () => {
    const c = lineChart({
        bars: [
            bar({ id: '1', group: '1', line: '1', start: '2026-10-05', end: '2026-10-08' }),
            bar({ id: '2', group: '1', line: '1', start: '2026-10-08', end: '2026-10-10', deps: ['1'] }),
        ],
    })
    const [arrow] = arrows(c.view)

    // the line's row is 36–72: the elbow runs at 70, in the gap under the bars
    assert.match(arrow.d, /V70 H/)
})

test('a dotted connector joins the pieces of a line where its work stops', () => {
    const c = lineChart()

    assert.deepEqual(c.gaps(c.view.rows[1]), [{ key: 'gap:2', x: 192 + 144, w: 528 - 336 }])
    assert.deepEqual(c.gaps(c.view.rows[2]), [])

    // a label drawn after its piece: the connector starts after it, never through it
    c.view.rows[1].bars[0].label = 'x'.repeat(25) // 165px, outside the 144px piece
    assert.deepEqual(c.gaps(c.view.rows[1]), [{ key: 'gap:2', x: 336 + 165 + 12, w: 528 - 336 - 177 }])
    assert.deepEqual(c.gapStyle({ x: 336, w: 192 }, c.view.rows[1]), { 'inset-inline-start': '336px', width: '192px', top: '18px' })
})

test('a label too long for its piece goes after it only when it fits before the next piece', () => {
    const c = lineChart()
    const row = c.view.rows[1]
    const first = row.bars[0]

    first.label = 'x'.repeat(25) // 165px: wider than the piece, narrower than the 192px gap
    assert.equal(c.labelOutside(first, row), true)
    first.label = 'x'.repeat(29) // 191px: no room
    assert.equal(c.labelOutside(first, row), false)
    assert.equal(c.labelOutside(first), true)
})

/* ------------------------------------------------------------------ dragging */

test('dragging a line\'s first piece moves the whole line, then sends it as one move', () => {
    const c = lineChart()
    const p = press(c, rowBar(c, '1'), { y: 50 })

    p.move(96)
    assert.equal(rowBar(c, '1').s, day(7))
    assert.equal(rowBar(c, '2').s, day(14), 'the next piece follows')
    assert.equal(rowBar(c, '4').s, day(6), 'another line stays')

    p.up(96)
    assert.deepEqual(c.wire.lines, [['1', '2026-10-07 00:00']])
    assert.deepEqual(c.wire.moves, [])
    // in the browser at once: every source bar and the piece outside the window
    const src = (id) => c.bars.find((b) => b.id === id)
    assert.equal(src('2').start, '2026-10-14 00:00')
    assert.equal(src('2')._pending, true)
    assert.equal(c.lineIndex.get('1').pieces[2].start, '2026-12-03 00:00')
    assert.equal(rowBar(c, '2').s, day(14))
})

test('any other piece moves alone, and so does the first with movePiecesAlone()', () => {
    let c = lineChart()
    let p = press(c, rowBar(c, '2'), { y: 50 })
    p.move(96)
    assert.equal(rowBar(c, '1').s, day(5))
    p.up(96)
    assert.deepEqual(c.wire.moves, [['2', '2026-10-14 00:00', '2026-10-16 00:00']])
    assert.deepEqual(c.wire.lines, [])

    c = lineChart({ movePiecesAlone: true })
    p = press(c, rowBar(c, '1'), { y: 50 })
    p.move(96)
    assert.equal(rowBar(c, '2').s, day(12))
    p.up(96)
    assert.deepEqual(c.wire.moves, [['1', '2026-10-07 00:00', '2026-10-10 00:00']])
})

test('the first piece is the earliest, wherever its record is: the line\'s own record first among equals', () => {
    const c = lineChart({ lines: [design({ pieces: [piece('2', '2026-10-01', '2026-10-03'), piece('1', '2026-10-05', '2026-10-08')] })] })
    assert.equal(c.carriesLine(rowBar(c, '2')), true)
    assert.equal(c.carriesLine(rowBar(c, '1')), false)

    const tie = lineChart({ lines: [design({ pieces: [piece('2', '2026-10-05', '2026-10-06'), piece('1', '2026-10-05', '2026-10-08')] })] })
    assert.equal(tie.firstPiece(tie.lineIndex.get('1')), '1')
})

test('a piece carried to another group carries its whole line, its dates shifted together', () => {
    const c = lineChart()
    const p = press(c, rowBar(c, '2'), { y: 50 })
    c.drag({ pointerId: 7, clientX: 148, clientY: 150 })

    assert.equal(c.target, '2')
    assert.equal(c.carried(rowBar(c, '1')), true)
    assert.equal(c.barStyle(rowBar(c, '1')).transform, 'translateY(108px)')
    assert.equal(c.gapStyle({ x: 0, w: 1 }, c.view.rows[1]).transform, 'translateY(108px)')
    assert.equal(rowBar(c, '1').s, day(6), 'the whole line moved a day')

    // back over its own group: the other pieces go back where they were
    c.drag({ pointerId: 7, clientX: 148, clientY: 50 })
    assert.equal(rowBar(c, '1').s, day(5))
    assert.equal(c.carried(rowBar(c, '1')), false)

    c.drag({ pointerId: 7, clientX: 100, clientY: 150 })
    p.up()
    assert.deepEqual(c.wire.lines, [['2', '2026-10-12 00:00', '2']])
    assert.deepEqual(c.bars.filter((b) => b.line === '1').map((b) => b.group), ['2', '2'])
    assert.deepEqual(c.groups.map((g) => g.count), [1, 3])
    assert.deepEqual(c.lineIndex.get('1').pieces.map((x) => x.group), ['2', '2', '2'])
})

test('a refused line goes back, every piece, and says why', async () => {
    const c = lineChart()
    c.$wire.ganttMoveLine = () => Promise.resolve({ ok: false, message: 'Design 3, on the same line: Not on Sundays.' })
    const notices = []
    c.notify = (m) => notices.push(m)

    const p = press(c, rowBar(c, '1'), { y: 50 })
    p.move(96)
    p.up(96)
    await tick()

    assert.deepEqual(c.bars.filter((b) => b.line === '1').map((b) => [b.start, b._pending]), [['2026-10-05', false], ['2026-10-12', false]])
    assert.equal(c.lineIndex.get('1').pieces[2].start, '2026-12-01 00:00')
    assert.equal(rowBar(c, '2').s, day(12))
    assert.deepEqual(notices, ['Design 3, on the same line: Not on Sundays.'])
    assert.equal(c.pending, 0)
})

test('the server\'s answer replaces every piece, and one that came into the window joins it', async () => {
    const c = lineChart()
    const moved = (id, start, end) => bar({ id, group: '1', line: '1', start: start + ' 00:00', end: end + ' 00:00', movable: true, regroupable: true })
    c.$wire.ganttMoveLine = () => Promise.resolve({ ok: true, bars: [moved('1', '2026-10-07', '2026-10-10'), moved('2', '2026-10-14', '2026-10-16'), moved('3', '2026-10-20', '2026-10-22')] })

    const p = press(c, rowBar(c, '1'), { y: 50 })
    p.move(96)
    p.up(96)
    await tick()

    assert.deepEqual(c.view.rows[1].bars.map((b) => b.id), ['1', '2', '3'])
    assert.equal(c.lineIndex.get('1').pieces[2].start, '2026-10-20 00:00')
    assert.equal(c.bars.some((b) => b._pending), false)
    assert.equal(c.flash, '1')
})

test('a line with a piece that may not move only changes rows', () => {
    const c = lineChart({ lines: [design({ pieces: [...design().pieces.slice(0, 2), piece('3', '2026-12-01', '2026-12-03', { movable: false })] })] })
    const p = press(c, rowBar(c, '1'), { y: 50 })
    p.move(96)
    assert.equal(rowBar(c, '1').s, day(5))
    p.up(96)
    assert.deepEqual(c.wire.lines, [])

    // a piece that may not change rows keeps the whole line in its group
    const d = lineChart({ lines: [design({ pieces: [...design().pieces.slice(0, 2), piece('3', '2026-12-01', '2026-12-03', { regroupable: false })] })] })
    const q = press(d, rowBar(d, '2'), { y: 50 })
    d.drag({ pointerId: 7, clientX: 100, clientY: 150 })
    assert.equal(d.target, null)
    q.up()
})

test('with preventLineOverlap() a piece stops at the next piece of its line, moved or resized', () => {
    const c = lineChart({ preventLineOverlap: true })

    let p = press(c, rowBar(c, '2'), { y: 50 })
    p.move(-48 * 6)
    assert.equal(rowBar(c, '2').s, day(8), 'stopped at the end of the first piece')
    p.up(-48 * 6)
    assert.deepEqual(c.wire.moves, [['2', '2026-10-08 00:00', '2026-10-10 00:00']])

    const d = lineChart({ preventLineOverlap: true, movePiecesAlone: true })
    p = press(d, rowBar(d, '1'), { y: 50, edge: 'end' })
    p.move(48 * 10)
    assert.equal(rowBar(d, '1').e, day(12))

    // pieces already overlapping (data from outside the chart): no wall, the server decides
    assert.equal(c.walls(c.lineIndex.get('1'), { id: '2', s: day(6), e: day(14) }), null)
})

test('a line moved by a piece with whole days snaps by days on a chart of hours', () => {
    const c = lineChart({ unit: 'hour', scales: ['hour', 'day'] })
    assert.equal(c.lineUnit('1'), DAY)
    c.lineIndex.get('1').pieces.forEach((x) => { x.allDay = false })
    assert.equal(c.lineUnit('1'), DAY / 24)
})

/* ------------------------------------------------------------------ the keyboard */

test('←/→ on a line\'s first piece move the line, on another piece that piece alone', () => fakeTimers((timers) => {
    const c = lineChart()
    key(c, rowBar(c, '1'), 'ArrowRight')
    assert.equal(rowBar(c, '2').s, day(13), 'the next piece follows')
    fire(timers)
    assert.deepEqual(c.wire.lines, [['1', '2026-10-06 00:00']])

    const d = lineChart()
    key(d, rowBar(d, '2'), 'ArrowRight')
    assert.equal(rowBar(d, '1').s, day(5))
    fire(timers)
    assert.deepEqual(d.wire.moves, [['2', '2026-10-13 00:00', '2026-10-15 00:00']])
}))

test('↑/↓ carry the whole line to the next group, and Escape brings it back', () => fakeTimers((timers) => {
    const c = lineChart()
    const focused = []
    c.$root.querySelector = (selector) => ({ focus: () => focused.push(selector) })
    document.activeElement = { dataset: { id: '2' } }

    key(c, rowBar(c, '2'), 'ArrowDown')
    assert.deepEqual(c.bars.filter((b) => b.line === '1').map((b) => b.group), ['2', '2'])
    assert.equal(c.view.rows.find((r) => r.key === 'l:2:1').bars.length, 2)
    assert.deepEqual(focused, ['.ps-gantt-bar[data-id="2"]'])

    c.onEscape()
    assert.deepEqual(c.bars.filter((b) => b.line === '1').map((b) => b.group), ['1', '1'])
    assert.deepEqual(c.wire.lines, [])

    key(c, rowBar(c, '2'), 'ArrowDown')
    fire(timers)
    assert.deepEqual(c.wire.lines, [['2', '2026-10-12 00:00', '2']])
    document.activeElement = null
}))

test('a piece\'s keys stop at the next piece with preventLineOverlap()', () => fakeTimers(() => {
    const c = lineChart({ preventLineOverlap: true })
    for (let i = 0; i < 6; i++) key(c, rowBar(c, '2'), 'ArrowLeft')
    assert.equal(rowBar(c, '2').s, day(8))
}))

/* ------------------------------------------------------------------ creating on a line */

test('a click on a line\'s row adds a piece to it; one on a group\'s row creates a task of its own', () => {
    const c = lineChart()
    const click = (row) => c.createAt({ target: { closest: () => null }, currentTarget: element(), clientX: 150, clientY: 60 }, row)

    click(c.view.rows[1])
    click(c.view.rows[2])
    click(c.view.rows[0])

    assert.deepEqual(c.wire.mounted.map(([, args]) => args.ganttLine), ['1', '4', undefined])
    assert.deepEqual(c.wire.mounted[0][1], { ganttStart: '2026-10-02 00:00', ganttEnd: '2026-10-03 00:00', ganttGroup: '1', ganttLine: '1' })
})

test('a selection on a line\'s row adds a piece, and turns red while it overlaps one with preventLineOverlap()', () => {
    const c = lineChart({ preventLineOverlap: true })
    const el = element()
    const row = c.view.rows[1]
    c.pressAt({ clientX: 100 + 48 * 8, clientY: 60, pointerType: 'mouse' })
    c.selectFrom({ button: 0, pointerId: 9, clientX: 100 + 48 * 8, clientY: 60, pointerType: 'mouse', target: { closest: () => null }, currentTarget: el }, row)

    c.drag({ pointerId: 9, clientX: 100 + 48 * 9, clientY: 60 }) // Oct 9–10: free
    assert.equal(c.pointer.overlaps, false)
    c.drag({ pointerId: 9, clientX: 100 + 48 * 12, clientY: 60 }) // to Oct 13: over the second piece
    assert.equal(c.pointer.overlaps, true)
    c.drag({ pointerId: 9, clientX: 100 + 48 * 9, clientY: 60 })
    c.up({ pointerId: 9, clientX: 100 + 48 * 9, clientY: 60 })

    assert.deepEqual(c.wire.mounted, [['create', { ganttStart: '2026-10-09 00:00', ganttEnd: '2026-10-11 00:00', ganttGroup: '1', ganttLine: '1' }]])

    // without the rule nothing turns red
    const d = lineChart()
    assert.equal(d.selectionOverlaps({ line: '1', key: 'l:1:1', s: day(6), e: day(7) }), false)
})

/* ------------------------------------------------------------------ refresh */

test('a refresh brings the lines with the bars', () => {
    const c = lineChart()
    c.apply({ window: { start: '2026-10-01', end: '2026-10-31' }, groups: c.groups, bars: c.bars, lines: [] })
    assert.equal(c.lineIndex.size, 0)
    assert.equal(c.view.rows[1].key, 'l:1:1', 'the bars still share their line')
    assert.equal(c.view.rows[1].label, 'Design')
    assert.equal(c.carriesLine(rowBar(c, '1')), false, 'without its pieces, a piece moves alone')

    c.apply({ window: { start: '2026-10-01', end: '2026-10-31' }, groups: c.groups, bars: c.bars, lines: [design()] })
    assert.equal(c.carriesLine(rowBar(c, '1')), true)
    assert.equal(parse(c.lineIndex.get('1').pieces[0].start), day(5))
})

/* ------------------------------------------------------------------ review fixes */

test('stops a piece a whole unit short of a neighbour off the grid, never where the server would round into it', () => {
    const P = (id, s, e) => ({ id, group: '1', start: s, end: e, allDay: false, milestone: false, movable: true, regroupable: true })
    const pieces = [P('1', '2026-10-05 00:00', '2026-10-06 00:00'), P('2', '2026-10-07 00:00', '2026-10-08 00:00'), P('3', '2026-10-09 12:00', '2026-10-10 00:00')]
    const c = chart({
        grouped: true, lineLayout: true, preventLineOverlap: true,
        groups: [{ key: '1', label: 'A', color: null, collapsed: false, count: 3 }],
        bars: pieces.map((p) => bar({ ...p, line: '1', label: 'x' + p.id })),
        lines: [{ key: '1', label: 'L', pieces }],
    })
    c.$refs.body = { getBoundingClientRect: () => ({ top: 0 }) }

    // dragged three days right: the 9th 12:00 stops it after one whole day, not 1.5
    const p = press(c, rowBar(c, '2'), { y: 50 })
    p.move(48 * 3)
    p.up(48 * 3)

    assert.deepEqual(c.wire.moves.at(-1).slice(0, 3), ['2', '2026-10-08 00:00', '2026-10-09 00:00'])
})

test('names the first piece as the server does when two pieces start together: the line\'s own record, then the key', () => {
    const c = lineChart()
    const line = (pieces) => ({ key: '9', label: 'L', pieces })

    assert.equal(c.firstPiece(line([piece('5', '2026-10-05', '2026-10-06'), piece('3', '2026-10-05', '2026-10-06'), piece('9', '2026-10-15', '2026-10-16')])), '3')
    assert.equal(c.firstPiece(line([piece('10', '2026-10-05', '2026-10-06'), piece('9', '2026-10-05', '2026-10-06')])), '9')
    assert.equal(c.firstPiece(line([piece('10', '2026-10-05', '2026-10-06'), piece('2', '2026-10-05', '2026-10-06')])), '2')
})

test('starts no drag on a piece whose line the keys just sent', () => fakeTimers(() => {
    const c = lineChart()

    key(c, rowBar(c, '1'), 'ArrowRight') // the first piece: the whole line
    const p = press(c, rowBar(c, '2'), { y: 50 })
    p.move(96)
    p.up(96)

    assert.equal(c.wire.lines.length, 1)
    assert.deepEqual(c.wire.moves, [])
}))

test('a refused ↓ and → on a line put every piece back in its group and on its dates', async () => {
    const c = lineChart()
    c.$wire.ganttMoveLine = () => Promise.resolve({ ok: false, message: 'Not in Beta.' })
    const notices = []
    c.notify = (m) => notices.push(m)

    await fakeTimers(async (timers) => {
        key(c, rowBar(c, '1'), 'ArrowDown')
        key(c, rowBar(c, '1'), 'ArrowRight')
        fire(timers)
    })
    await tick()

    assert.deepEqual(c.bars.filter((b) => b.line === '1').map((b) => [b.id, String(b.group), b.start, b._pending]), [['1', '1', '2026-10-05', false], ['2', '1', '2026-10-12', false]])
    assert.deepEqual(c.lineIndex.get('1').pieces.map((p) => [p.group, p.start]), [['1', '2026-10-05 00:00'], ['1', '2026-10-12 00:00'], ['1', '2026-12-01 00:00']])
    assert.deepEqual(c.groups.map((g) => g.count), [3, 1])
    assert.deepEqual(notices, ['Not in Beta.'])
})
