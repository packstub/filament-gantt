import { test } from 'node:test'
import assert from 'node:assert/strict'
import { chart, bar, rowBar, press, element, safeUrl, layout, DAY } from './harness.mjs'

/* ------------------------------------------------------------------ tooltips */

test('a whole-day bar reads as dates, its last day included; a bar with times reads as moments', () => {
    const c = chart({ bars: [bar(), bar({ id: '2', start: '2026-10-05 08:00', end: '2026-10-05 12:00', allDay: false, label: 'Shift' })] })

    assert.equal(c.tooltip(rowBar(c, '1')), 'Bar\nOct 5, 2026 – Oct 7, 2026')
    assert.equal(c.tooltip(rowBar(c, '2')), 'Shift\nOct 5, 2026 08:00 – Oct 5, 2026 12:00')
})

test('a milestone reads as one date, a bar with progress says so', () => {
    const c = chart({ bars: [bar({ milestone: true, end: '2026-10-05' }), bar({ id: '2', progress: 40 })] })

    assert.equal(c.tooltip(rowBar(c, '1')), 'Bar\nOct 5, 2026\nMilestone')
    assert.equal(c.tooltip(rowBar(c, '2')), 'Bar\nOct 5, 2026 – Oct 7, 2026\n40% done')
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

    assert.match(c.barStyle(rowBar(c)), /height:40px;.*--ps-gantt-row-inner:40px/)
    assert.doesNotMatch(c.barStyle(rowBar(c)), /width:/)
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

/* ------------------------------------------------------------------ the bar menu */

test('a finger held on a bar opens the menu; a mouse or a finger that moved does not', () => {
    const timers = []
    const realSet = globalThis.setTimeout
    const realClear = globalThis.clearTimeout
    globalThis.setTimeout = (fn, ms) => { timers.push({ fn, ms }); return timers.length }
    globalThis.clearTimeout = (id) => { if (id) timers[id - 1].fn = null }

    try {
        const c = chart({ barActions: [{ name: 'edit' }], bars: [bar()] })

        press(c, rowBar(c), { pointerType: 'mouse' })
        assert.equal(timers.length, 0)
        c.cancel()

        press(c, rowBar(c), { pointerType: 'touch', x: 120, y: 60 })
        assert.equal(timers.length, 1)
        assert.equal(timers[0].ms, 500)
        timers[0].fn()
        assert.equal(c.pointer, null)
        assert.equal(c.menu.bar, rowBar(c))
        assert.deepEqual([c.menu.x, c.menu.y], [120, 60])
        c.closeMenu()

        const p = press(c, rowBar(c), { pointerType: 'touch' })
        p.move(50)
        assert.equal(timers[1].fn, null) // disarmed by the drag
        p.up(50)
        assert.equal(c.menu, null)
        assert.equal(c.wire.moves.length, 1)
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
        const el = { scrollLeft: sign * 180, clientWidth: 400 }
        c.$refs.scroll = el
        c.persist = () => {}

        c.setScale('day') // day 10 + 200px/18ppd ≈ 21.1 days from the start were centred
        const centre = (180 + 200) / 18
        assert.equal(c.scale, 'day')
        assert.equal(el.scrollLeft, sign * (centre * 48 - 200))
    }
    globalThis.__direction = 'ltr'
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
