/**
 * A tiny stand-in for the browser, enough to run the Alpine component's methods
 * in Node (no DOM, no Alpine): the globals gantt.js touches, a component factory
 * and a fake pointer.
 */

globalThis.getComputedStyle = () => ({ direction: globalThis.__direction || 'ltr' })
globalThis.window = { open() {}, location: { href: '' }, addEventListener() {}, removeEventListener() {}, innerHeight: 800, innerWidth: 1200, scrollY: 0, history: { replaceState() {}, state: null } }
globalThis.document = { hidden: false, activeElement: null }
globalThis.localStorage = { getItem: () => null, setItem() {} }

const { default: packstubGantt, layout, arrows, parse, format, phpDate, pack, isoWeekday, isoDay, safeUrl, tone, DAY, HOUR } = await import('../../resources/dist/gantt.js')

export { layout, arrows, parse, format, phpDate, pack, isoWeekday, isoDay, safeUrl, tone, DAY, HOUR }

export const config = {
    window: { start: '2026-10-01', end: '2026-10-31' }, groups: [], bars: [], filters: [], barActions: [], barAction: null, createAction: null,
    readOnly: false, compact: false, height: null, i18n: { milestone: 'Milestone', progress: ':percent% done', failed: 'Failed', offline: 'Offline', bar_menu: 'Bar actions' },
    scale: 'day', scales: ['day', 'week', 'month', 'year'], unit: 'day', minMinutes: 1440, maxMinutes: null, today: '2026-10-14', pxPerDay: { hour: 960, day: 48, week: 18, month: 5, year: 1.5 }, rowHeight: 36,
    firstDayOfWeek: 1, weekendDays: [6, 7], locale: 'en', dateFormat: 'M j, Y', dateTimeFormat: 'M j, Y H:i', grouped: false, key: 'k', poll: null,
}

/** A bar as the server sends it. */
export function bar(over = {}) {
    return { id: '1', group: '', start: '2026-10-05', end: '2026-10-08', allDay: true, milestone: false, label: 'Bar', movable: true, resizable: true, ...over }
}

/**
 * Alpine hands back a reactive proxy, not the object that was assigned: `this.pointer
 * = p; this.pointer === p` is false. The component is wrapped the same way for the
 * state it compares by identity, so a check against the plain object fails here too.
 */
function reactive(component) {
    const proxies = new WeakMap()

    return new Proxy(component, {
        set(target, key, value) {
            if (['pointer', 'keyboard', 'menu'].includes(key) && value && typeof value === 'object') {
                if (! proxies.has(value)) proxies.set(value, new Proxy(value, {}))
                value = proxies.get(value)
            }
            target[key] = value

            return true
        },
    })
}

/** A component with the chart laid out, its $wire calls recorded in `wire`. */
export function chart(over = {}) {
    const c = reactive(packstubGantt({ ...config, ...over }))
    c.$refs = {}
    c.$root = { style: { setProperty() {} } }
    c.$nextTick = (fn) => fn()
    c.$dispatch = () => {}
    c.wire = { moves: [], mounted: [], refreshes: [] }
    c.$wire = {
        ganttMove: (...args) => { c.wire.moves.push(args); return { then: () => ({ catch: () => ({ finally() {} }) }) } },
        ganttRefresh: (...args) => { c.wire.refreshes.push(args); return { then() {} } },
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
