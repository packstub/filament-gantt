/**
 * A tiny stand-in for the browser, enough to run the Alpine component's methods
 * in Node (no DOM, no Alpine): the globals gantt.js touches, a component factory
 * and a fake pointer.
 */

globalThis.getComputedStyle = () => ({ direction: globalThis.__direction || 'ltr' })
globalThis.window = { open() {}, location: { href: '' }, addEventListener() {}, removeEventListener() {}, innerHeight: 800, innerWidth: 1200, scrollY: 0, history: { replaceState() {}, state: null } }
globalThis.document = { hidden: false, activeElement: null }
globalThis.localStorage = { getItem: () => null, setItem() {} }

const { default: packstubGantt, layout, parse, format, phpDate, pack, isoWeekday, safeUrl, DAY } = await import('../../resources/dist/gantt.js')

export { layout, parse, format, phpDate, pack, isoWeekday, safeUrl, DAY }

export const config = {
    window: { start: '2026-10-01', end: '2026-10-31' }, groups: [], bars: [], filters: [], barActions: [], barAction: null, createAction: null,
    readOnly: false, compact: false, height: null, i18n: { milestone: 'Milestone', progress: ':percent% done', failed: 'Failed', offline: 'Offline', bar_menu: 'Bar actions' },
    scale: 'day', unit: 'day', minMinutes: 1440, maxMinutes: null, today: '2026-10-14', pxPerDay: { day: 48, week: 18, month: 5 }, rowHeight: 36,
    firstDayOfWeek: 1, weekendDays: [6, 7], locale: 'en', dateFormat: 'M j, Y', dateTimeFormat: 'M j, Y H:i', grouped: false, key: 'k', poll: null,
}

/** A bar as the server sends it. */
export function bar(over = {}) {
    return { id: '1', group: '', start: '2026-10-05', end: '2026-10-08', allDay: true, milestone: false, label: 'Bar', movable: true, resizable: true, ...over }
}

/** A component with the chart laid out, its $wire calls recorded in `wire`. */
export function chart(over = {}) {
    const c = packstubGantt({ ...config, ...over })
    c.$refs = {}
    c.$root = { style: { setProperty() {} } }
    c.$nextTick = (fn) => fn()
    c.$dispatch = () => {}
    c.wire = { moves: [], mounted: [], refreshes: [], windows: [] }
    c.$wire = {
        ganttMove: (...args) => { c.wire.moves.push(args); return { then: () => ({ catch: () => ({ finally() {} }) }) } },
        ganttRefresh: (...args) => { c.wire.refreshes.push(args); return { then() {} } },
        ganttWindow: (...args) => { c.wire.windows.push(args); return { then() {} } },
        mountAction: (name, args) => { c.wire.mounted.push([name, args]) },
    }
    c.view = layout(c.bars, c.axis(), c.scale)
    c.rebuild()

    return c
}

/** The laid-out copy of a bar, the one the pointer works on. */
export function rowBar(c, id = '1') {
    return c.rowBar(id)
}

export const element = () => ({ setPointerCapture() {}, releasePointerCapture() {}, focus() { document.activeElement = this }, getBoundingClientRect: () => ({ left: 100, top: 50, right: 200, bottom: 78 }) })

/** pointerdown on a bar (or on one of its edges) at x, then the events to follow. */
export function press(c, target, { x = 100, y = 50, edge = null, pointerType = 'mouse', el = element() } = {}) {
    c.down({ button: 0, pointerId: 7, clientX: x, clientY: y, pointerType, target: { dataset: edge ? { edge } : {} }, currentTarget: el }, target)

    return {
        el,
        move: (dx) => c.drag({ pointerId: 7, clientX: x + dx, clientY: y }),
        up: (dx = 0, keys = {}) => c.up({ pointerId: 7, clientX: x + dx, clientY: y, ...keys }),
    }
}
