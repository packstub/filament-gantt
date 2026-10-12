import { test } from 'node:test'
import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import { chart, bar, rowBar, press, element, safeUrl, layout, DAY } from './harness.mjs'

/* ------------------------------------------------------------------ start-up */

test('a config without a window (a chart that appeared after the component\'s first render) asks the server for its state', () => {
    const c = chart({ window: { start: null, end: null }, bars: [], groups: [] })
    // the harness lays out on construction; the component itself starts with an empty view
    Object.assign(c.view, { days: 0, width: 0, columns: [], top: [], shades: [], rows: [] })
    c.init()
    assert.deepEqual(c.wire.refreshes, [[{}, null, null, false, false]])
    // nothing is drawn for the round trip: no one-column, no-row chart before the answer
    assert.equal(c.view.columns.length, 0)
    assert.equal(c.view.rows.length, 0)

    // a config with its state starts from it
    const d = chart({ bars: [bar()] })
    d.init()
    assert.deepEqual(d.wire.refreshes, [])
    assert.equal(d.view.rows.length, 1)
})

test('a swipe that starts on a bar scrolls the chart: the browser keeps panning on bars', async () => {
    const css = await readFile(new URL('../../resources/dist/gantt.css', import.meta.url), 'utf8')
    const block = /\.ps-gantt-bar \{[^}]*\}/.exec(css)[0]

    assert.match(block, /touch-action: manipulation;/)
    assert.doesNotMatch(css, /touch-action: none/)
})

/* ------------------------------------------------------------------ tooltips */

test('a whole-day bar reads as dates, its last day included; a bar with times reads as moments', () => {
    const c = chart({ bars: [bar(), bar({ id: '2', start: '2026-10-05 08:00', end: '2026-10-05 12:00', allDay: false, label: 'Shift' })] })

    assert.equal(c.tooltip(rowBar(c, '1')), 'Bar\nOct 5, 2026 – Oct 7, 2026')
    assert.equal(c.tooltip(rowBar(c, '2')), 'Shift\nOct 5, 2026 08:00 – 12:00') // one day: the date once, then the time part of the format
})

test('a milestone reads as one date, a bar with progress says so', () => {
    const c = chart({ bars: [bar({ milestone: true, end: '2026-10-05' }), bar({ id: '2', progress: 40 })] })

    assert.equal(c.tooltip(rowBar(c, '1')), 'Bar\nOct 5, 2026\nMilestone')
    assert.equal(c.tooltip(rowBar(c, '2')), 'Bar\nOct 5, 2026 – Oct 7, 2026\n40% done')
})

test('a whole-day bar of a single day reads as one date; a one-day bar with times still reads as two moments', () => {
    const c = chart({ bars: [bar({ start: '2026-10-05', end: '2026-10-06' }), bar({ id: '2', start: '2026-10-05 00:00', end: '2026-10-06 00:00', allDay: false })] })

    assert.equal(c.tooltip(rowBar(c, '1')), 'Bar\nOct 5, 2026')
    assert.equal(c.tooltip(rowBar(c, '2')), 'Bar\nOct 5, 2026 00:00 – Oct 6, 2026 00:00')
})

/* ------------------------------------------------------------------ rows in the DOM */

test('above 200 rows only the rows in view are drawn, and the pads keep the height', () => {
    const c = chart({ bars: Array.from({ length: 300 }, (_, i) => bar({ id: String(i) })) })
    c.$refs.scroll = { scrollTop: 5000, clientHeight: 600 }
    c.rebuild()

    const { from, to, top, bottom } = c.slice
    assert.equal(c.windowed, true)
    assert.ok(c.offsets[from] <= 5000 - 400 + 36 && c.offsets[from] + 36 >= 5000 - 400)
    assert.ok(c.offsets[to - 1] + 36 >= 5000 + 600 + 400)
    assert.equal(top + (to - from) * 36 + bottom, 300 * 36)

    c.$refs.scroll = { scrollTop: 300 * 36, clientHeight: 600 }
    c.onScroll()
    assert.equal(c.slice.to, 300)
    assert.ok(c.slice.from < 300)
})

test('a milestone centres its diamond on the row\'s inner height', () => {
    const c = chart({ rowHeight: 48, bars: [bar({ milestone: true, end: '2026-10-05' })] })

    assert.equal(c.barStyle(rowBar(c)).height, '40px')
    assert.equal(c.barStyle(rowBar(c))['--ps-gantt-row-inner'], '40px')
    assert.equal(c.barStyle(rowBar(c)).width, undefined)
})

/* ------------------------------------------------------------------ moving and resizing */

test('a drag moves the bar by whole units, keeps the time of day and sends the new range', () => {
    const c = chart({ bars: [bar({ start: '2026-10-05 09:00', end: '2026-10-05 09:00', allDay: false, milestone: true })] })
    const p = press(c, rowBar(c))
    p.move(48)
    p.up(48)

    assert.deepEqual(c.wire.moves, [['1', '2026-10-06 09:00', null]])
})

test('a drag attempt on a bar that cannot move is not a click: no action, no move', () => {
    const c = chart({ barAction: 'edit', barActions: [{ name: 'edit' }], bars: [bar({ movable: false, resizable: false, url: '/tasks/1' })] })
    let href = null
    globalThis.window.location = { set href(v) { href = v }, get href() { return href } }

    const p = press(c, rowBar(c))
    p.move(200)
    p.up(200)

    assert.deepEqual(c.wire.mounted, [])
    assert.deepEqual(c.wire.moves, [])
    assert.equal(href, null)
    assert.equal(rowBar(c).s, Date.UTC(2026, 9, 5))

    // a plain click still opens the action
    press(c, rowBar(c)).up()
    assert.deepEqual(c.wire.mounted, [['edit', { ganttRecord: '1' }]])
})

test('a resize is clamped to minDuration and maxDuration before the server sees it', () => {
    const c = chart({ minMinutes: 2 * 1440, maxMinutes: 5 * 1440, bars: [bar({ start: '2026-10-05', end: '2026-10-08' })] })

    let p = press(c, rowBar(c), { edge: 'end' })
    p.move(-48 * 2) // 3 days → 1 day asked, 2 allowed
    assert.equal(rowBar(c).e, Date.UTC(2026, 9, 7))
    p.move(48 * 10) // 13 days asked, 5 allowed
    assert.equal(rowBar(c).e, Date.UTC(2026, 9, 10))
    p.up(48 * 10)
    assert.deepEqual(c.wire.moves, [['1', '2026-10-05 00:00', '2026-10-10 00:00']])

    const d = chart({ minMinutes: 2 * 1440, maxMinutes: 5 * 1440, bars: [bar({ start: '2026-10-05', end: '2026-10-10' })] })
    p = press(d, rowBar(d), { edge: 'start' })
    p.move(-48 * 10)
    assert.equal(rowBar(d).s, Date.UTC(2026, 9, 5)) // 5 days at most
    p.move(48 * 4)
    assert.equal(rowBar(d).s, Date.UTC(2026, 9, 8)) // 2 days at least
})

test('a min or max that is not a whole unit is rounded to one, so the server accepts what the browser allowed', () => {
    const c = chart({ minMinutes: 36 * 60, maxMinutes: 4 * 1440 + 720, bars: [bar({ start: '2026-10-05', end: '2026-10-08' })] })
    assert.deepEqual(c.limits, { min: 2 * DAY, max: 4 * DAY })

    const p = press(c, rowBar(c), { edge: 'end' })
    p.move(-96) // 1 day asked; 36 hours means 2 whole days at least, never 1.5
    assert.equal(rowBar(c).e, Date.UTC(2026, 9, 7))
    p.move(480) // 13 days asked; 4.5 days means 4 whole days at most
    assert.equal(rowBar(c).e, Date.UTC(2026, 9, 9))
    p.up(480)
    assert.deepEqual(c.wire.moves, [['1', '2026-10-05 00:00', '2026-10-09 00:00']])

    // at the hour unit the same bounds are whole hours
    const h = chart({ unit: 'hour', minMinutes: 90, maxMinutes: 200, bars: [bar()] })
    assert.deepEqual(h.limits, { min: 2 * 3600000, max: 3 * 3600000 })
})

test('one pointer at a time: a second finger neither starts a drag nor ends the first', () => {
    const c = chart({ bars: [bar(), bar({ id: '2', start: '2026-10-10', end: '2026-10-12' })] })
    const p = press(c, rowBar(c, '1'))
    p.move(96)

    // a second finger lands on another bar, then lifts (its lostpointercapture)
    c.down({ button: 0, pointerId: 8, clientX: 300, clientY: 90, pointerType: 'touch', target: { dataset: {} }, currentTarget: element() }, rowBar(c, '2'))
    assert.equal(c.pointer.id, 7)
    c.cancel({ pointerId: 8 })
    assert.equal(c.pointer.id, 7)
    assert.equal(c.dragging, true)
    assert.equal(rowBar(c, '1').s, Date.UTC(2026, 9, 7))

    // the first finger's release commits its drag; its own lostpointercapture afterwards is nothing
    p.up(96)
    c.cancel({ pointerId: 7 })
    assert.deepEqual(c.wire.moves, [['1', '2026-10-07 00:00', '2026-10-10 00:00']])
    assert.equal(rowBar(c, '1').s, Date.UTC(2026, 9, 7))

    // the dragging pointer's own pointercancel still puts the bar back
    const d = chart({ bars: [bar()] })
    const q = press(d, rowBar(d))
    q.move(96)
    d.cancel({ pointerId: 7 })
    assert.equal(d.pointer, null)
    assert.equal(rowBar(d).s, Date.UTC(2026, 9, 5))
})

test('Escape (or a lost pointer) cancels the drag: the bar goes back, nothing is sent', () => {
    const c = chart({ bars: [bar()] })
    const p = press(c, rowBar(c))
    p.move(96)
    assert.equal(rowBar(c).s, Date.UTC(2026, 9, 7))
    assert.equal(c.dragging, true)

    c.onEscape()
    assert.equal(c.pointer, null)
    assert.equal(c.dragging, false)
    assert.equal(c.hint, null)
    assert.equal(rowBar(c).s, Date.UTC(2026, 9, 5))
    assert.equal(rowBar(c).x, 4 * 48)

    p.up(96) // the release that follows is nothing
    assert.deepEqual(c.wire.moves, [])
})

test('a bar in the air keeps following the pointer when another bar\'s answer rebuilds the chart', () => {
    const c = chart({ bars: [bar(), bar({ id: '2', start: '2026-10-10', end: '2026-10-12' })] })
    const p = press(c, rowBar(c))
    p.move(96)
    const before = c.pointer.bar

    c.replace({ ...bar({ id: '2', start: '2026-10-20', end: '2026-10-22' }) })

    assert.notEqual(c.pointer.bar, before)
    assert.equal(c.pointer.bar, rowBar(c, '1'))
    assert.equal(rowBar(c, '1').s, Date.UTC(2026, 9, 7))
    assert.equal(rowBar(c, '1').x, 6 * 48)

    p.move(144)
    assert.equal(rowBar(c, '1').s, Date.UTC(2026, 9, 8))
    p.up(144)
    assert.deepEqual(c.wire.moves, [['1', '2026-10-08 00:00', '2026-10-11 00:00']])
})

/* ------------------------------------------------------------------ links */

test('only http(s), mailto, tel and relative URLs are followed', () => {
    assert.equal(safeUrl('javascript:alert(1)'), null)
    assert.equal(safeUrl(' JavaScript:alert(1)'), null)
    assert.equal(safeUrl('java\nscript:alert(1)'), null)
    assert.equal(safeUrl('data:text/html,hi'), null)
    assert.equal(safeUrl('vbscript:x'), null)
    assert.equal(safeUrl('DATA:text/html,x'), null)
    assert.equal(safeUrl('blob:https://x'), null)
    assert.equal(safeUrl('file:///etc/passwd'), null)
    assert.equal(safeUrl('//evil.test/x'), null)
    assert.equal(safeUrl('/\t/evil.test'), null)
    assert.equal(safeUrl('\\\\evil.test'), null)
    assert.equal(safeUrl('/\\evil.test'), null)
    assert.equal(safeUrl(''), null)
    assert.equal(safeUrl('/tasks/1'), '/tasks/1')
    assert.equal(safeUrl('tasks/1?x=1#y'), 'tasks/1?x=1#y')
    assert.equal(safeUrl('https://example.test/a'), 'https://example.test/a')
    assert.equal(safeUrl('mailto:a@b.c'), 'mailto:a@b.c')
    assert.equal(safeUrl('tel:+1'), 'tel:+1')
})

test('a click follows a safe url and ignores an unsafe one', () => {
    const c = chart()
    let href = null
    globalThis.window.location = { set href(v) { href = v }, get href() { return href } }

    c.open({ id: '1', url: 'javascript:alert(1)', movable: true }, { metaKey: false, ctrlKey: false, shiftKey: false })
    assert.equal(href, null)

    c.open({ id: '1', url: '/tasks/1', movable: true }, { metaKey: false, ctrlKey: false, shiftKey: false })
    assert.equal(href, '/tasks/1')
})

test('a link opened aside gets no opener: the new tab cannot steer this one', () => {
    const opened = []
    globalThis.window.open = (...args) => opened.push(args)
    const aside = { metaKey: false, ctrlKey: true, shiftKey: false }

    const c = chart()
    c.open({ id: '1', url: 'https://example.test/a', movable: true }, aside)

    // with a click action too: the modifier means "the link, in a new tab"
    const d = chart({ barAction: 'edit', barActions: [{ name: 'edit' }] })
    d.open({ id: '1', url: 'https://example.test/b', movable: true }, aside)
    d.open({ id: '1', url: 'javascript:alert(1)', movable: true }, aside)

    assert.deepEqual(opened, [
        ['https://example.test/a', '_blank', 'noopener,noreferrer'],
        ['https://example.test/b', '_blank', 'noopener,noreferrer'],
    ])
    assert.deepEqual(d.wire.mounted, [['edit', { ganttRecord: '1' }]])
})

/* ------------------------------------------------------------------ the bar menu */

test('on touch a swipe scrolls, a held bar follows the finger, and held then lifted opens the menu', () => {
    const timers = []
    const realSet = globalThis.setTimeout
    const realClear = globalThis.clearTimeout
    globalThis.setTimeout = (fn, ms) => { timers.push({ fn, ms }); return timers.length }
    globalThis.clearTimeout = (id) => { if (id) timers[id - 1].fn = null }

    try {
        const c = chart({ barActions: [{ name: 'edit' }], barAction: 'edit', bars: [bar()] })

        // a mouse drags at once, no timer
        press(c, rowBar(c), { pointerType: 'mouse' })
        assert.equal(timers.length, 0)
        c.cancel()

        // a swipe before the hold is the browser's scroll: no drag, no move, no click
        let p = press(c, rowBar(c), { pointerType: 'touch' })
        assert.equal(timers[0].ms, 450)
        p.move(40)
        assert.equal(c.pointer, null)
        assert.equal(rowBar(c).s, Date.UTC(2026, 9, 5))
        p.up(40)
        assert.deepEqual(c.wire.moves, [])
        assert.deepEqual(c.wire.mounted, [])

        // held still, the bar is picked up and follows the finger
        p = press(c, rowBar(c), { pointerType: 'touch' })
        timers.at(-1).fn()
        assert.equal(c.pointer.picked, true)
        p.move(96)
        assert.equal(rowBar(c).s, Date.UTC(2026, 9, 7))
        p.up(96)
        assert.deepEqual(c.wire.moves, [['1', '2026-10-07 00:00', '2026-10-10 00:00']])

        // held, then lifted without moving: the menu, at the finger
        const d = chart({ barActions: [{ name: 'edit' }], bars: [bar()] })
        p = press(d, rowBar(d), { pointerType: 'touch', x: 120, y: 60 })
        timers.at(-1).fn()
        p.up()
        assert.equal(d.menu.bar, rowBar(d))
        assert.deepEqual([d.menu.x, d.menu.y], [120, 60])

        // a tap runs the click action, as before
        const e = chart({ barActions: [{ name: 'edit' }], barAction: 'edit', bars: [bar()] })
        press(e, rowBar(e), { pointerType: 'touch' }).up()
        assert.deepEqual(e.wire.mounted, [['edit', { ganttRecord: '1' }]])
    } finally {
        globalThis.setTimeout = realSet
        globalThis.clearTimeout = realClear
    }
})

test('the menu opens at the pointer, stays inside the viewport, and arrows move the focus', () => {
    const c = chart({ barActions: [{ name: 'edit' }, { name: 'delete' }], bars: [bar()] })
    const items = [element(), element()]
    c.$refs.menu = { offsetWidth: 200, offsetHeight: 100, querySelectorAll: () => items }
    const from = element()

    c.openMenu({ clientX: 1150, clientY: 780, currentTarget: from }, rowBar(c))
    assert.deepEqual([c.menu.x, c.menu.y], [1200 - 200 - 8, 800 - 100 - 8])
    assert.equal(document.activeElement, items[0])

    c.menuMove(1)
    assert.equal(document.activeElement, items[1])
    c.menuMove(1)
    assert.equal(document.activeElement, items[0])
    c.menuMove(-1)
    assert.equal(document.activeElement, items[1])

    c.onEscape()
    assert.equal(c.menu, null)
    assert.equal(document.activeElement, from)

    // Shift+F10 sends no coordinates: the menu opens under the bar
    c.openMenu({ clientX: 0, clientY: 0, currentTarget: from }, rowBar(c))
    assert.deepEqual([c.menu.x, c.menu.y], [100, 78])

    // a bar with no actions of its own has no menu
    c.closeMenu()
    c.openMenu({ clientX: 10, clientY: 10 }, { ...rowBar(c), actions: [] })
    assert.equal(c.menu, null)
})

/* ------------------------------------------------------------------ zoom in RTL */

test('zooming keeps the same day centred, in RTL too', () => {
    for (const [direction, sign] of [['ltr', 1], ['rtl', -1]]) {
        globalThis.__direction = direction
        const c = chart({ scale: 'week', bars: [bar()] })
        const el = { scrollLeft: sign * 180, clientWidth: 400, querySelector: () => ({ offsetWidth: 0 }) }
        c.$refs.scroll = el
        c.persist = () => {}

        c.setScale('day') // day 10 + 200px/18ppd ≈ 21.1 days from the start were centred
        const centre = (180 + 200) / 18
        assert.equal(c.scale, 'day')
        assert.equal(el.scrollLeft, sign * (centre * 48 - 200))
    }
    globalThis.__direction = 'ltr'
})

test('a scale chosen from the tabs keeps the day in the middle of the chart, after the sticky label column', () => {
    const c = chart({ scale: 'day', scales: ['hour', 'day', 'week'], bars: [bar()] })
    const el = { scrollLeft: 480, clientWidth: 1000, querySelector: () => ({ offsetWidth: 200 }) }
    c.$refs.scroll = el
    c.persist = () => {}
    // 800 px of chart are visible: the day at its middle is (480 + 400) / 48
    const centre = (480 + 400) / 48

    c.setScale('hour')
    assert.equal(el.scrollLeft, centre * 960 - 400)
    // forth and back lands where it started
    c.setScale('day')
    assert.equal(el.scrollLeft, 480)
    // a level too coarse for the same middle starts at the window's start
    c.setScale('week')
    assert.equal(el.scrollLeft, 0)
})

/* ------------------------------------------------------------------ the layout's contract with the component */

test('a row bar carries what the server sent, and its pixels', () => {
    const c = chart({ bars: [bar({ allDay: false, start: '2026-10-05 06:00', end: '2026-10-05 18:00' })] })
    const b = rowBar(c)

    assert.equal(b.allDay, false)
    assert.equal(b.x, 4 * 48 + 12)
    assert.equal(b.w, 24)
    assert.equal(b.src, c.bars[0])
    assert.equal(layout(c.bars, c.axis(), 'day').rows[0].bars[0].src, c.bars[0])
    assert.equal(DAY, 86400000)
})
