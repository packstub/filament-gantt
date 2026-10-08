import { test } from 'node:test'
import assert from 'node:assert/strict'
import { layout, arrows, parse, format, phpDate, pack, isoWeekday, DAY } from './harness.mjs'

const window = {
    start: '2026-10-05', end: '2026-11-01', today: '2026-10-14',
    pxPerDay: 18, rowHeight: 36, firstDayOfWeek: 1, weekendDays: [6, 7], locale: 'en',
    groups: [{ key: '1', label: 'Acme', color: 'sky', collapsed: false, count: 0 }], grouped: true, compact: false, collapsed: {},
}
const bars = [
    { id: 'a', group: '1', start: '2026-10-05 00:00', end: '2026-10-08 00:00', milestone: false, label: 'A', movable: true, resizable: true },
    { id: 'b', group: '1', start: '2026-10-06 00:00', end: '2026-10-10 00:00', milestone: false, label: 'B', movable: true, resizable: true },
    { id: 'm', group: '2', start: '2026-10-20 00:00', end: '2026-10-20 00:00', milestone: true, label: 'M', movable: true, resizable: false },
    { id: 'z', group: '2', start: 'garbage', end: null, milestone: false, label: 'Z', movable: true, resizable: true },
]

test('dates parse and format as UTC, whatever the machine\'s zone', () => {
    assert.equal(parse('2026-10-05'), Date.UTC(2026, 9, 5))
    assert.equal(parse('2026-10-05 09:30'), Date.UTC(2026, 9, 5, 9, 30))
    assert.equal(parse('2026-10-05T09:30:15'), Date.UTC(2026, 9, 5, 9, 30, 15))
    assert.equal(parse('garbage'), null)
    assert.equal(format(Date.UTC(2026, 9, 5, 9, 30)), '2026-10-05 09:30')
    assert.equal(format(Date.UTC(2026, 9, 5), false), '2026-10-05')
    assert.equal(isoWeekday(parse('2026-10-05')), 1)
    assert.equal(isoWeekday(parse('2026-10-11')), 7)
    assert.equal(phpDate(parse('2026-10-05 14:05'), 'M j, Y H:i', 'en'), 'Oct 5, 2026 14:05')
    assert.equal(phpDate(parse('2026-10-05 14:05'), 'D d/m/y g:i a \\a\\t', 'en'), 'Mon 05/10/26 2:05 pm at')
    assert.equal(DAY, 86400000)
})

test('week scale: week columns, month header, shades, today, group rows and bar pixels', () => {
    const v = layout(bars, window, 'week')
    assert.equal(v.days, 28)
    assert.equal(v.width, 28 * 18)
    assert.deepEqual(v.columns.map((c) => c.w), [126, 126, 126, 126])
    assert.equal(v.columns[0].label, '5 Oct')
    assert.equal(v.top.length, 2)
    assert.equal(v.top[0].label, 'October 2026')
    assert.equal(v.top[1].x, 27 * 18)
    assert.equal(v.shades.length, 8)
    assert.equal(v.todayX, 9 * 18)
    assert.deepEqual(v.rows.map((r) => r.type), ['group', 'bar', 'bar', 'group', 'bar'])
    assert.equal(v.rows[0].group.label, 'Acme')
    assert.equal(v.rows[0].group.count, 2)
    assert.equal(v.rows[3].group.key, '2')
    assert.equal(v.rows[3].group.count, 1) // Z was skipped (no start)
    const a = v.rows[1].bars[0]
    assert.equal(a.x, 0)
    assert.equal(a.w, 3 * 18)
    assert.equal(a.src, bars[0])
    const m = v.rows[4].bars[0]
    assert.equal(m.x, 15 * 18)
    assert.equal(m.w, 0)
})

test('day scale: one column per day with weekend and today flags', () => {
    const v = layout(bars, { ...window, pxPerDay: 48 }, 'day')
    assert.equal(v.columns.length, 28)
    assert.equal(v.columns[0].label, 'Mon 5')
    // a label wider than its cell falls back to the day number
    assert.equal(layout(bars, window, 'day').columns[0].label, '5')
    assert.equal(v.columns[5].weekend, true)
    assert.equal(v.columns[9].today, true)
})

test('month scale: month columns, years on top, no shades', () => {
    const v = layout(bars, window, 'month')
    assert.equal(v.columns.length, 2)
    assert.equal(v.columns[0].label, 'Oct')
    assert.equal(v.columns[0].w, 27 * 18)
    assert.equal(v.top[0].label, '2026')
    assert.equal(v.shades.length, 0)
})

test('week columns follow firstDayOfWeek and a mid-week window start', () => {
    assert.deepEqual(layout(bars, { ...window, firstDayOfWeek: 7 }, 'week').columns.slice(0, 2).map((c) => c.w), [6 * 18, 7 * 18])
    const v = layout([], { ...window, start: '2026-10-07', end: '2026-11-03' }, 'week')
    assert.deepEqual(v.columns.slice(0, 2).map((c) => c.w), [5 * 18, 7 * 18])
})

test('a collapsed group hides its bars', () => {
    const v = layout(bars, { ...window, collapsed: { 1: true } }, 'week')
    assert.deepEqual(v.rows.map((r) => r.type), ['group', 'group', 'bar'])
    assert.equal(v.rows[0].collapsed, true)
})

test('compact: one row per group, overlapping bars in lanes', () => {
    const v = layout(bars, { ...window, compact: true }, 'week')
    assert.deepEqual(v.rows.map((r) => r.type), ['lanes', 'lanes'])
    assert.equal(v.rows[0].height, 2 * 36)
    assert.deepEqual(v.rows[0].bars.map((b) => b.lane), [0, 1])
    assert.equal(v.rows[1].height, 36)
})

test('ungrouped: bar rows only', () => {
    const v = layout(bars.slice(0, 2).map((b) => ({ ...b, group: '' })), { ...window, groups: [], grouped: false }, 'week')
    assert.deepEqual(v.rows.map((r) => r.type), ['bar', 'bar'])
})

test('pack(): touching bars share a lane, overlapping ones do not', () => {
    const packed = [{ x: 0, w: 10 }, { x: 12, w: 10 }, { x: 5, w: 10 }, { x: 40, w: 0, milestone: true }]
    assert.equal(pack(packed), 2)
    assert.deepEqual(packed.map((b) => b.lane), [0, 0, 1, 0])
})

test('edge windows: today outside, a single day, a leap February', () => {
    assert.equal(layout([], { ...window, today: '2027-01-01' }, 'week').todayX, null)
    const one = layout([], { ...window, end: '2026-10-05' }, 'day')
    assert.equal(one.days, 1)
    assert.equal(one.columns.length, 1)
    assert.equal(layout([], { ...window, start: '2028-02-01', end: '2028-03-01' }, 'day').columns.length, 30)
})

test('day scale at the default zoom keeps every day label; a cell too narrow shows the day number, then nothing', () => {
    const days = layout([], { ...window, start: '2026-10-01', end: '2026-10-31', pxPerDay: 48 }, 'day').columns.map((c) => c.label)
    assert.equal(days.filter((l) => l === '').length, 0)
    assert.equal(days[0], 'Thu 1')
    assert.equal(days[13], 'Wed 14')
    // 24px a day: "Wed 14" does not fit, "14" does
    assert.equal(layout([], { ...window, pxPerDay: 24 }, 'day').columns[9].label, '14')
    // 2px a day: no room for a day number either
    assert.equal(layout([], { ...window, pxPerDay: 2 }, 'day').columns[9].label, '')
    assert.equal('short' in layout([], window, 'day').columns[0], false)
})

test('arrows(): finish-to-start paths between bars on the chart, dashed when the successor starts too early', () => {
    const deps = [
        { id: 'a', group: '1', start: '2026-10-05', end: '2026-10-08', label: 'A', movable: true, resizable: true },
        { id: 'b', group: '1', start: '2026-10-10', end: '2026-10-12', label: 'B', movable: true, resizable: true, deps: ['a'] },
        { id: 'c', group: '1', start: '2026-10-07', end: '2026-10-09', label: 'C', movable: true, resizable: true, deps: ['a', 'zzz', 'c'] },
    ]
    const v = layout(deps, window, 'week')
    const out = arrows(v)
    // rows: group (0), a (36), b (72), c (108); a bar's centre is 4 + 28 / 2 into its row
    assert.deepEqual(out.map((a) => a.key), ['a>b', 'a>c'])
    assert.equal(out[0].d, 'M54,54 H62 V90 H90 m-6,-4 l6,4 l-6,4')
    assert.equal(out[0].late, false)
    // c starts before a ends: around through the gap, and late
    assert.equal(out[1].d, 'M54,54 H62 V90 H28 V126 H36 m-6,-4 l6,4 l-6,4')
    assert.equal(out[1].late, true)

    // a predecessor in a folded group, or outside the window, draws nothing
    const folded = layout(deps, { ...window, collapsed: { 1: true } }, 'week')
    assert.deepEqual(arrows(folded), [])
    // (the server sends only the bars in the window: a predecessor it left out draws nothing)
    assert.deepEqual(arrows(layout(deps.slice(1), window, 'week')), [])

    // compact: lanes stack inside one row, the arrow's y follows the lane
    const compact = layout(deps, { ...window, compact: true }, 'week')
    const lanes = arrows(compact)
    assert.equal(lanes.find((a) => a.key === 'a>c').d.startsWith('M54,18 '), true)
    assert.equal(lanes.find((a) => a.key === 'a>c').d.includes('V54'), true, 'c is in the second lane')

    // elbows stay inside the canvas: a successor at the window's edge, a predecessor ending at its end
    const edge = layout([
        { id: 'a', group: '1', start: '2026-10-05', end: '2026-11-02', label: 'A', movable: true, resizable: true },
        { id: 'b', group: '1', start: '2026-10-05', end: '2026-10-07', label: 'B', movable: true, resizable: true, deps: ['a'] },
    ], window, 'week')
    assert.equal(arrows(edge)[0].d, 'M504,54 H504 V72 H0 V90 H0 m-6,-4 l6,4 l-6,4')

    // a bar carried to another row takes its arrows along
    const carried = arrows(v, { id: 'b', dy: 36 })
    assert.equal(carried[0].d, 'M54,54 H62 V126 H90 m-6,-4 l6,4 l-6,4')
})

test('year scale: month columns over quarters, no shades, the today line', () => {
    const v = layout(bars, { ...window, start: '2026-10-01', end: '2027-12-31', pxPerDay: 1.5 }, 'year')
    assert.equal(v.columns.length, 15)
    assert.equal(v.columns[0].label, 'Oct')
    assert.deepEqual(v.top.map((q) => q.label), ['Q4 2026', 'Q1 2027', 'Q2 2027', 'Q3 2027', 'Q4 2027'])
    assert.equal(Math.round(v.top[1].x), Math.round(92 * 1.5))
    assert.equal(v.shades.length, 0)
    assert.equal(v.todayX, 13 * 1.5)
    // a quarter cut short by the window's edge keeps its short label
    assert.equal(layout([], { ...window, start: '2026-12-15', end: '2027-03-31', pxPerDay: 1.5 }, 'year').top[0].label, 'Q4')
})
