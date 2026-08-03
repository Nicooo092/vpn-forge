/*
 * Table motion -- sort, filter, pagination, fetch.
 * -----------------------------------------------
 * Tables are the densest screens in this panel and the ones an operator spends
 * the day inside, so this is where motion has to be most disciplined. Everything
 * in here answers a question the operator just asked:
 *
 *   - sorting rearranges the rows you are already looking at, and FLIP carries
 *     each one from where it was to where it now belongs, so you can follow the
 *     record you were reading instead of re-finding it;
 *   - filtering resizes the table, and the container eases to its new height
 *     instead of the page jumping under the cursor;
 *   - paging turns the page: the old records leave toward where you came from,
 *     the new ones arrive from where you are going;
 *   - a column header says, before you click it, that it is sortable, and says
 *     afterwards which way it went;
 *   - and while the request is out, a hairline sweeps the top edge of the card.
 *
 * THE ONE RULE THAT SHAPES THE WHOLE FILE: none of this may fire on a poll.
 * Every table in this panel refreshes itself on a timer -- 10s on the services
 * list, 30s on traffic logs and change history, 60s on connection logs -- and a
 * poll re-renders the tbody wholesale. Anything triggered by "the rows changed"
 * would therefore animate on its own every few seconds forever, which is exactly
 * the flicker the CSS layer refuses to ship.
 *
 * So nothing here watches the DOM. Everything is driven from Livewire's `commit`
 * hook, and a commit is only acted on when its payload names something the
 * operator did. The list of what counts is not invented: it is Filament's own
 * `Table::LOADING_TARGETS` (vendor/filament/tables/src/Table.php:55), the same
 * list Filament uses to decide when to disable a control and show a spinner,
 * plus the paginator methods and the `wire:model.live` properties behind them.
 * A poll is `wire:poll.30s` with no expression, which Livewire evaluates as
 * `$wire.$commit()` (vendor/livewire/livewire/dist/livewire.esm.js:11927) -- a
 * commit with an empty `calls` array and an empty `updates` object. It matches
 * nothing on an allowlist, so it can never reach a single tween in this file.
 *
 * The rest of the constraints are the panel's usual ones:
 *
 *   - NOTHING IS LEFT UNREADABLE. Two effects here dim content (a page leaving,
 *     rows arriving) and both are guarded: a timed failsafe forces every node
 *     back to full opacity whether or not the tween, the response or the morph
 *     ever arrived.
 *   - TRANSFORMS ARE TRANSIENT. A live transform makes its element the
 *     containing block for `position: fixed` descendants, and Filament renders
 *     action modals inline. Every transform written here is cleared with
 *     `clearProps` the moment its tween lands, and nothing holds one at rest.
 *   - Only transform, opacity and custom properties are tweened. The one
 *     exception is the container height on a filter change, which is a layout
 *     property by nature; it is bounded, one element, and cleared afterwards.
 */

import { Flip } from 'gsap/Flip'

/* The stylesheet this module injects. Removed and rebuilt on every run so a
   navigation cannot leave two of them in the head. */
const STYLE_ID = 'vf-tables'

/* Marks DOM this module created. gsap.context() reverts tweens; it has no idea
   we appended anything, so every node we make carries this and every run sweeps
   the previous one's by attribute before it builds. */
const OWNED_ATTR = 'data-vf-tables'

/* Marks a `.fi-ta` whose Livewire request is in flight. Swept at the start of
   every run: gsap.context() reverts tweens, not attributes, and a table left
   wearing this after an interrupted run would sweep its loading bar forever. */
const BUSY_ATTR = 'data-vf-ta-busy'

/* Filament gives every record row -- and every record card in a content layout
   -- a `wire:key` of `{componentId}.table.records.{key}`. That key is what makes
   Livewire's morph reuse the same DOM node for the same record across a sort,
   which is the whole reason FLIP has anything to match. It is also what tells a
   record row apart from the column-search row, group headers and summary rows,
   none of which carry one. */
const RECORD_SELECTOR = '[wire\\:key*=".table.records."]'

/* Livewire methods that mean "the operator changed this table". Split by what
   kind of movement each one produces rather than lumped together, because the
   answer to a sort and the answer to a page turn are different animations. */
const SORT_CALLS = ['sortTable']
const PAGE_CALLS = ['gotoPage', 'nextPage', 'previousPage', 'setPage']
const FILTER_CALLS = [
    'removeTableFilter',
    'removeTableFilters',
    'resetTableFiltersForm',
    'applyTableFilters',
    'resetTableSearch',
    'resetTableColumnSearch',
    'resetTableColumnSearches',
    'applyTableColumnManager',
    'resetTableColumnManager',
    'resetTable',
]

/* Livewire PROPERTIES behind the same intents. A search box is
   `wire:model.live.debounce` on `tableSearch`, a filter is `wire:model.live` on
   `tableFilters.*`, and neither produces a method call -- they arrive as a
   property diff in `commit.updates`, keyed with dots. */
const SORT_PROPS = ['tableSort']
const FILTER_PROPS = [
    'tableFilters',
    'tableDeferredFilters',
    'tableSearch',
    'tableColumnSearches',
    'tableRecordsPerPage',
    'tableGrouping',
    'tableColumns',
]

/* Above this many rows the FLIP measurement costs more than the reordering says.
   Filament's page sizes here are 10/25/50, so this only ever bites on an
   "all records" page, which is precisely the page where it should. */
const MAX_FLIP_ROWS = 60

/* Cap on how many rows may be staggered in at once, for the same reason. */
const MAX_ENTER_ROWS = 40

/* Below this the height change is not worth easing -- a single row appearing at
   the bottom of a long table is not a layout shift anyone notices. */
const HEIGHT_THRESHOLD = 24

/* How long a pagination lead-out, or a row fading in, may stay dim before the
   failsafe forces it back. Comfortably inside the panel's one-second rule while
   still leaving a slow-but-arriving response room to land first. */
const VISIBILITY_GUARD = 0.9

/* A pending commit that never produced a morph is dropped after this. Cheaper
   and more reliable than a timer per request. */
const PENDING_TTL = 30000

/* A click on a pagination control stays meaningful for about this long. Past it
   the commit that arrived almost certainly belongs to something else. */
const GESTURE_TTL = 2500

/* How long after a keypress or a click a PROPERTY diff still counts as
   something the operator did. Comfortably past the 500ms debounce Filament puts
   on its search field, and nowhere near a polling interval. */
const INPUT_TTL = 1800

const CSS = `
/* Registered so GSAP can interpolate them: it reads the computed value before
   it tweens, and an unregistered custom property reads back as an empty
   string. --vf-ta-sorted starts at 1 on purpose -- the sorted column's marker
   is drawn whether or not any JavaScript ever ran. */
@property --vf-ta-hover { syntax: '<number>'; inherits: false; initial-value: 0; }
@property --vf-ta-sorted { syntax: '<number>'; inherits: false; initial-value: 1; }

/* No new palette. --vf-accent is motion.css's own token, itself derived from
   the primary scale Filament emits inline on :root, so the literals below are
   only reached if that stylesheet is missing entirely. */
:root {
    --vf-ta-line: var(--vf-accent, var(--primary-600, #d97706));
    --vf-ta-line-soft: color-mix(in oklab, var(--vf-ta-line) 45%, transparent);
    --vf-ta-line-hot: color-mix(in oklab, var(--vf-ta-line) 85%, white);
}

.dark {
    --vf-ta-line: var(--vf-accent, var(--primary-400, #fbbf24));
    --vf-ta-line-soft: color-mix(in oklab, var(--vf-ta-line) 38%, transparent);
    --vf-ta-line-hot: color-mix(in oklab, var(--vf-ta-line) 80%, white);
}

/* --- Column headers --------------------------------------------------------
   The header cell becomes the positioning context for its own underline.
   Filament leaves it static and puts nothing absolutely positioned inside it,
   so this changes no layout and captures nothing else.                       */

.fi-ta-header-cell {
    position: relative;
}

/* Hover preview: a muted rule wiping in from the leading edge, which is the
   panel saying "this column sorts" before you commit to finding out. Scoped to
   columns that are NOT the sorted one, so it can never be misread as the
   current sort state. The width comes from a custom property because GSAP owns
   it -- a CSS transition here would smear against the tween. */
.fi-ta-header-cell:not(.fi-ta-header-cell-sorted)::before {
    content: '';
    position: absolute;
    inset-inline: 0;
    bottom: 0;
    height: 2px;
    border-radius: 2px;
    pointer-events: none;
    background-color: var(--vf-ta-line-soft);
    transform: scaleX(var(--vf-ta-hover, 0));
    transform-origin: 0 50%;
}

[dir='rtl'] .fi-ta-header-cell:not(.fi-ta-header-cell-sorted)::before {
    transform-origin: 100% 50%;
}

/* The real marker. Full width at rest; the wipe is a one-shot the JS plays by
   tweening --vf-ta-sorted up from 0 when a sort lands, so the failure mode of
   every code path below is a correctly drawn, static accent rule. */
.fi-ta-header-cell-sorted::after {
    content: '';
    position: absolute;
    inset-inline: 0;
    bottom: 0;
    height: 2px;
    border-radius: 2px;
    pointer-events: none;
    background-color: var(--vf-ta-line);
    transform: scaleX(var(--vf-ta-sorted, 1));
    transform-origin: 0 50%;
}

[dir='rtl'] .fi-ta-header-cell-sorted::after {
    transform-origin: 100% 50%;
}

/* --- Request in flight -----------------------------------------------------
   A hairline sweeping the top edge of the card while the table is fetching.

   It animates background-position rather than a translated child, which is the
   opposite of what motion.css does for its skeletons -- deliberately. That rule
   avoided background-position because it repainted a whole card every frame;
   here the painted surface is the full width by two pixels, so the composited
   version would cost more to set up than the repaint costs to run. And a
   translated segment would need a clip, which on .fi-ta-ctn means
   \`overflow: hidden\` on a flex container that has an open filters dropdown
   hanging out of it exactly when a filter request is in flight.

   The 160ms delay with no fill mode is what stops a fast response from flashing
   a bar: during the delay the element keeps its own \`opacity: 0\`, and only the
   keyframes ever raise it. It mirrors Filament's own
   \`filament.livewire_loading_delay\`, which gates its spinners the same way. */

@keyframes vf-ta-sweep {
    from {
        opacity: 1;
        background-position-x: 100%;
    }
    to {
        opacity: 1;
        background-position-x: 0%;
    }
}

.fi-ta[${BUSY_ATTR}] > .fi-ta-ctn::after {
    content: '';
    position: absolute;
    inset-inline: 0;
    top: 0;
    height: 2px;
    z-index: 3;
    pointer-events: none;
    opacity: 0;
    border-start-start-radius: inherit;
    border-start-end-radius: inherit;
    background-repeat: no-repeat;
    background-size: 200% 100%;
    background-image: linear-gradient(
        90deg,
        transparent 30%,
        var(--vf-ta-line) 45%,
        var(--vf-ta-line-hot) 50%,
        var(--vf-ta-line) 55%,
        transparent 70%
    );
    animation: vf-ta-sweep 1.15s linear 160ms infinite;
}

/* The cursor is the cheapest signal there is and the one nobody misses. */
.fi-ta[${BUSY_ATTR}] .fi-ta-content-ctn {
    cursor: progress;
}
`

/**
 * Table sort / filter / pagination motion.
 *
 * @param {{gsap: import('gsap').gsap, ScrollTrigger: object, MOTION: object}} api
 */
export function tables({ gsap, MOTION }) {
    if (typeof document === 'undefined' || !document.body) {
        return null
    }

    gsap.registerPlugin(Flip)

    // Undo the previous run before anything else. The runtime reverts our
    // tweens and our nested context, but attributes we wrote are ours to clean
    // up -- and a run interrupted mid-request would otherwise leave a table
    // sweeping its loading bar for the life of the page.
    document.querySelectorAll(`[${BUSY_ATTR}]`).forEach((node) => node.removeAttribute(BUSY_ATTR))

    /*
     * `gsap.context()` with no argument hands back the context we are currently
     * running inside -- the runtime's. Tweens created later, from a Livewire
     * hook or a pointer handler, would otherwise be born with no context at all:
     * nothing would revert them, and the inline transforms they left behind
     * would survive the next navigation frozen mid-animation.
     */
    const host = gsap.context()

    /**
     * Runs `fn` as part of the runtime's context and hands back its result. The
     * result travels through a closure rather than a return value because
     * `context.add()` treats a returned FUNCTION as a cleanup callback and would
     * invoke it on revert.
     */
    const owned = (fn) => {
        let result

        if (host) {
            host.add(() => {
                result = fn()
            })
        } else {
            result = fn()
        }

        return result
    }

    const scope = createScope()

    // gsap.context() reverts tweens, not listeners or DOM. This nested context
    // exists only so the runtime's revert() reaches the disposer below.
    gsap.context(() => () => scope.dispose())

    const api = { gsap, MOTION, owned, rtl: document.dir === 'rtl' }

    injectStylesheet(scope)
    headerAffordance(api, scope)

    const gestures = trackPaginationGestures(scope)
    const input = trackUserInput(scope)

    livewireBridge(api, scope, gestures, input)

    return null
}

/* ------------------------------------------------------------------------- */
/* Lifecycle helpers                                                          */
/* ------------------------------------------------------------------------- */

/**
 * A bag of teardown callbacks. Every listener, hook registration and DOM
 * insertion in this file goes through one of these, so nothing survives a
 * context revert.
 */
function createScope() {
    const disposers = []

    return {
        on(target, type, handler, options) {
            if (!target || !target.addEventListener) {
                return
            }

            target.addEventListener(type, handler, options)
            disposers.push(() => target.removeEventListener(type, handler, options))
        },
        add(disposer) {
            if (typeof disposer === 'function') {
                disposers.push(disposer)
            }
        },
        dispose() {
            while (disposers.length) {
                try {
                    disposers.pop()()
                } catch (error) {
                    // A failing disposer must not strand the ones behind it.
                    console.warn('[vpn-forge motion] tables cleanup failed', error)
                }
            }
        },
    }
}

function injectStylesheet(scope) {
    // By attribute, not by id: a run interrupted between creating the element
    // and setting its id would otherwise orphan a stylesheet nothing can find.
    document.querySelectorAll(`[${OWNED_ATTR}]`).forEach((node) => node.remove())

    const style = document.createElement('style')
    style.id = STYLE_ID
    style.setAttribute(OWNED_ATTR, 'style')
    style.textContent = CSS
    document.head.appendChild(style)

    scope.add(() => style.remove())

    // The stylesheet goes, so the custom properties it interprets mean nothing
    // -- but an inline `--vf-ta-hover: 0.4` left on a header cell would be
    // reinterpreted by the NEXT run's stylesheet as a half-drawn underline.
    scope.add(() => {
        document.querySelectorAll(`[${BUSY_ATTR}]`).forEach((node) => {
            node.removeAttribute(BUSY_ATTR)
        })

        document.querySelectorAll('.fi-ta-header-cell').forEach((cell) => {
            cell.style.removeProperty('--vf-ta-hover')
            cell.style.removeProperty('--vf-ta-sorted')
        })
    })
}

/* ------------------------------------------------------------------------- */
/* Reading the table                                                          */
/* ------------------------------------------------------------------------- */

/**
 * Every Filament table inside a Livewire component root. A page normally has
 * one; a page with relation managers or table widgets has each in its own
 * component, so this is scoped to the component that actually committed.
 */
function tablesIn(root) {
    if (!root || typeof root.querySelectorAll !== 'function') {
        return []
    }

    const found = Array.from(root.querySelectorAll('.fi-ta'))

    if (root.classList && root.classList.contains('fi-ta')) {
        found.unshift(root)
    }

    return found
}

/**
 * The record rows (or record cards, in a content layout) of one table, in
 * document order. Group headers, summary rows and the column-search row are
 * excluded by construction -- none of them carries a record `wire:key`.
 */
function recordNodes(scope) {
    if (!scope || typeof scope.querySelectorAll !== 'function') {
        return []
    }

    return Array.from(scope.querySelectorAll(RECORD_SELECTOR))
}

/**
 * The block that holds the records and nothing else. For a page turn this is
 * what travels; the header row, the toolbar and the paginator stay put, because
 * they are the frame the page turns inside.
 */
function contentBody(ta) {
    const ctn = ta.querySelector('.fi-ta-content-ctn')

    if (!ctn) {
        return null
    }

    return (
        ctn.querySelector(':scope > .fi-ta-content') ||
        ctn.querySelector(':scope > .fi-ta-table > tbody') ||
        ctn.querySelector('.fi-ta-table > tbody') ||
        null
    )
}

/** The currently sorted header cell, or null when the table is unsorted. */
function sortedHeader(ta) {
    return ta.querySelector('.fi-ta-header-cell-sorted')
}

/**
 * The page number Filament is currently showing, read off the paginator's own
 * active item. Returns NaN when the table has no numbered pagination, which is
 * the caller's cue to fall back to the control the operator clicked.
 */
function activePage(ta) {
    const label = ta.querySelector('.fi-pagination-item.fi-active .fi-pagination-item-label')

    if (!label) {
        return NaN
    }

    // `Number::format()` groups thousands, and the separator is locale
    // dependent -- stripping everything that is not a digit is the only reading
    // that survives both "1,234" and "1 234".
    return Number((label.textContent || '').replace(/\D/g, ''))
}

/* ------------------------------------------------------------------------- */
/* Intent detection                                                           */
/* ------------------------------------------------------------------------- */

/**
 * What, if anything, the operator just asked this table to do.
 *
 * A strict allowlist, and that is the point. A poll commits with no calls and no
 * property diff; an action modal commits `mountAction`; a bulk selection commits
 * nothing at all. None of them appear below, so none of them can reach a tween.
 *
 * The second gate is for the property path only. A METHOD call can only have
 * come from a control being activated, so it is trusted outright. A property
 * diff cannot: Alpine writes back to entangled properties as it initialises, and
 * a poll firing seconds later would carry that write and look like a filter
 * change. Requiring a recent keypress or click closes that, and closes nothing
 * else -- every real filter, search and page-size change is a keypress or a
 * click by definition.
 *
 * @param {{calls?: Array, updates?: object}} commit
 * @param {boolean} userActed
 * @returns {?{kind: 'sort'|'page'|'filter', method?: string, params?: Array}}
 */
function classify(commit, userActed) {
    if (!commit) {
        return null
    }

    const calls = Array.isArray(commit.calls) ? commit.calls : []

    for (const call of calls) {
        const method = call && call.method
        const params = (call && call.params) || []

        if (!method) {
            continue
        }

        if (SORT_CALLS.includes(method)) {
            return { kind: 'sort' }
        }

        if (PAGE_CALLS.includes(method)) {
            return { kind: 'page', method, params }
        }

        if (FILTER_CALLS.includes(method)) {
            return { kind: 'filter' }
        }

        // `$wire.set('tableSearch', ...)` from a custom Blade reaches the server
        // as a $set call rather than a property diff. Same intent, different
        // shape on the wire.
        if (method === '$set' && typeof params[0] === 'string') {
            const byProperty = classifyProperty(params[0])

            if (byProperty) {
                return byProperty
            }
        }
    }

    if (!userActed) {
        return null
    }

    const updates = commit.updates && typeof commit.updates === 'object' ? commit.updates : {}

    for (const key of Object.keys(updates)) {
        const byProperty = classifyProperty(key)

        if (byProperty) {
            return byProperty
        }
    }

    return null
}

/**
 * When the operator last did something. Capture phase, because an Alpine handler
 * that stops propagation must not be able to hide the fact that they did.
 */
function trackUserInput(scope) {
    let at = 0

    const mark = () => {
        at = Date.now()
    }

    ;['pointerdown', 'keydown', 'input', 'change'].forEach((type) => {
        scope.on(document, type, mark, { capture: true, passive: true })
    })

    return {
        recent() {
            return Date.now() - at < INPUT_TTL
        },
    }
}

/**
 * Property names arrive dotted -- `tableFilters.status.value`,
 * `tableColumnSearches.name` -- so only the root is meaningful.
 */
function classifyProperty(key) {
    const root = String(key).split('.')[0]

    if (SORT_PROPS.includes(root)) {
        return { kind: 'sort' }
    }

    if (FILTER_PROPS.includes(root)) {
        return { kind: 'filter' }
    }

    return null
}

/**
 * Which way the page turned.
 *
 * `nextPage`/`previousPage` say it outright. `gotoPage(n)` carries the
 * destination, which is only useful next to the page we were on. `setPage()` --
 * what a cursor paginator emits -- carries an opaque cursor and says nothing at
 * all, which is why the clicked control is tracked separately: Filament puts
 * `rel="prev"` / `rel="next"` on every one of them
 * (vendor/filament/support/resources/views/components/pagination/index.blade.php).
 */
function pageDirection(intent, ta, gesture) {
    if (intent.method === 'nextPage') {
        return 1
    }

    if (intent.method === 'previousPage') {
        return -1
    }

    if (intent.method === 'gotoPage') {
        const target = Number(intent.params[0])
        const current = activePage(ta)

        if (Number.isFinite(target) && Number.isFinite(current) && target !== current) {
            return target > current ? 1 : -1
        }
    }

    if (gesture === 'prev') {
        return -1
    }

    if (gesture === 'next') {
        return 1
    }

    // Unknowable. Forward is the honest default: it is what every control except
    // "previous" and "first" does.
    return 1
}

/**
 * Remembers which pagination control was last pressed, so a commit that cannot
 * say which way it is going can ask.
 */
function trackPaginationGestures(scope) {
    let last = null

    scope.on(
        document,
        'pointerdown',
        (event) => {
            const target = event.target

            if (!target || typeof target.closest !== 'function') {
                return
            }

            const control = target.closest(
                '.fi-pagination-item, .fi-pagination-previous-btn, .fi-pagination-next-btn',
            )

            if (!control) {
                return
            }

            const rel = control.getAttribute('rel')

            last = {
                at: Date.now(),
                rel: rel === 'prev' || rel === 'first' ? 'prev' : rel === 'next' || rel === 'last' ? 'next' : null,
            }
        },
        { capture: true, passive: true },
    )

    scope.add(() => {
        last = null
    })

    return {
        read() {
            if (!last || Date.now() - last.at > GESTURE_TTL) {
                return null
            }

            return last.rel
        },
    }
}

/* ------------------------------------------------------------------------- */
/* Visibility guards                                                          */
/* ------------------------------------------------------------------------- */

/*
 * Every effect in this file that lowers opacity registers one of these. The
 * guard is keyed by the element it protects so a second request can cancel the
 * first one's guard instead of having it fire in the middle of the new
 * animation.
 */
const guards = new WeakMap()

function disarmGuard(key) {
    const call = guards.get(key)

    if (!call) {
        return
    }

    guards.delete(key)

    try {
        call.kill()
    } catch (error) {
        // Already finished. Nothing to do.
    }
}

/**
 * Force `nodes` back to a fully readable state after `delay` seconds unless
 * something disarms it first.
 *
 * This is insurance, not choreography. A response that never lands, a morph that
 * never fires, a tween killed by a navigation -- none of them may leave a value
 * an operator is trying to read sitting at 30% opacity.
 */
function armGuard(api, key, nodes, delay) {
    const { gsap, MOTION, owned } = api

    disarmGuard(key)

    const call = owned(() =>
        gsap.delayedCall(delay, () => {
            guards.delete(key)

            nodes.forEach((node) => {
                if (!node || !node.isConnected) {
                    return
                }

                if (Number(gsap.getProperty(node, 'opacity')) >= 0.99) {
                    return
                }

                gsap.to(node, {
                    opacity: 1,
                    x: 0,
                    y: 0,
                    duration: MOTION.fast,
                    ease: MOTION.easeSoft,
                    overwrite: true,
                    clearProps: 'opacity,transform,willChange',
                })
            })
        }),
    )

    if (call) {
        guards.set(key, call)
    }
}

/* ------------------------------------------------------------------------- */
/* The Livewire bridge                                                        */
/* ------------------------------------------------------------------------- */

/**
 * Three hooks, in the order Livewire fires them:
 *
 *   commit  -- the request is going out. Classify it; if it is ours, mark the
 *              table busy and start the page leaving.
 *   morph   -- the response is here and the DOM is about to be patched. This is
 *              the only moment the OLD layout still exists, so it is where FLIP
 *              takes its state and where the container's height is measured.
 *   morphed -- the DOM is final. Play.
 *
 * `respond` is deliberately not used to detect the new DOM: Livewire calls it
 * BEFORE the morph (livewire.esm.js:8661), so it is good for exactly one thing,
 * which is knowing the request came back.
 */
function livewireBridge(api, scope, gestures, input) {
    const pending = new Map()

    const onCommit = ({ component, commit, respond }) => {
        purge(pending)

        const intent = classify(commit, input.recent())

        if (!intent || !component || !component.el) {
            return
        }

        const list = tablesIn(component.el)

        if (!list.length) {
            return
        }

        if (intent.kind === 'page') {
            intent.direction = pageDirection(intent, list[0], gestures.read())
        }

        intent.at = Date.now()
        pending.set(component.id, intent)

        list.forEach((ta) => {
            ta.setAttribute(BUSY_ATTR, '')

            if (intent.kind === 'page') {
                leadOut(api, ta, intent.direction)
            }
        })

        let settled = false

        const settle = () => {
            if (settled) {
                return
            }

            settled = true
            list.forEach((ta) => ta.removeAttribute(BUSY_ATTR))
        }

        if (typeof respond === 'function') {
            respond(settle)
        }

        // A request that never comes back -- aborted by a navigation, dropped
        // offline -- must not leave a table sweeping a loading bar forever.
        api.owned(() => api.gsap.delayedCall(15, settle))
    }

    const onMorph = ({ el, component }) => {
        if (!component) {
            return
        }

        const intent = pending.get(component.id)

        if (!intent) {
            return
        }

        // Measure the outgoing layout while it still exists. Reads only: not one
        // write happens between here and the end of the loop, so this costs a
        // single layout pass no matter how many tables the component holds.
        intent.snapshots = tablesIn(el || component.el).map((ta) => capture(ta, intent.kind))
    }

    const onMorphed = ({ component }) => {
        if (!component) {
            return
        }

        const intent = pending.get(component.id)

        if (!intent) {
            return
        }

        pending.delete(component.id)

        if (!intent.snapshots) {
            return
        }

        intent.snapshots.forEach((snapshot) => play(api, snapshot, intent))
    }

    const register = () => {
        const livewire = window.Livewire

        if (!livewire || typeof livewire.hook !== 'function') {
            return false
        }

        // Livewire.hook() hands back its own unregister function
        // (livewire.esm.js:8460), which is what keeps repeated boots from
        // stacking one set of hooks per visited page.
        scope.add(livewire.hook('commit', onCommit))
        scope.add(livewire.hook('morph', onMorph))
        scope.add(livewire.hook('morphed', onMorphed))
        scope.add(() => pending.clear())

        return true
    }

    if (!register()) {
        // Only reachable if this module somehow runs before Livewire's script
        // has been parsed. Harmless either way: with no hooks registered nothing
        // in this file animates, and the panel is exactly as the server sent it.
        scope.on(document, 'livewire:init', register, { once: true })
    }

    // A navigation takes the table with it. Clearing the busy state here rather
    // than waiting for the next boot means the loading bar cannot ride across
    // the page transition.
    scope.on(document, 'livewire:navigating', () => {
        document.querySelectorAll(`[${BUSY_ATTR}]`).forEach((node) => node.removeAttribute(BUSY_ATTR))
    })
}

/** Drop intents whose response never arrived, rather than holding their DOM. */
function purge(pending) {
    if (!pending.size) {
        return
    }

    const now = Date.now()

    pending.forEach((intent, id) => {
        if (now - (intent.at || 0) > PENDING_TTL) {
            pending.delete(id)
        }
    })
}

/* ------------------------------------------------------------------------- */
/* Capture and play                                                           */
/* ------------------------------------------------------------------------- */

/**
 * Everything about the outgoing table that will not exist in a moment.
 *
 * @param {HTMLElement} ta
 * @param {'sort'|'page'|'filter'} kind
 */
function capture(ta, kind) {
    const snapshot = {
        ta,
        ctn: ta.querySelector('.fi-ta-content-ctn'),
        height: 0,
        state: null,
        keys: null,
        sortedTh: kind === 'sort' ? sortedHeader(ta) : null,
    }

    if (!snapshot.ctn) {
        return snapshot
    }

    if (kind === 'filter') {
        snapshot.height = snapshot.ctn.offsetHeight
        snapshot.indicators = indicatorSignature(ta)
    }

    if (kind === 'sort' || kind === 'filter') {
        const rows = recordNodes(snapshot.ctn)

        // Which records were on screen before, so the ones that were not can be
        // told apart afterwards. A row that merely MOVED is carried by FLIP; a
        // row that is genuinely new arrives.
        snapshot.keys = new Set()

        rows.forEach((row) => {
            const key = row.getAttribute('wire:key')

            if (key) {
                snapshot.keys.add(key)
            }
        })

        if (kind === 'sort' && rows.length && rows.length <= MAX_FLIP_ROWS) {
            snapshot.state = Flip.getState(rows)
        }
    }

    return snapshot
}

function play(api, snapshot, intent) {
    if (!snapshot || !snapshot.ta || !snapshot.ta.isConnected) {
        return
    }

    if (intent.kind === 'sort') {
        playSort(api, snapshot)
        playSortIndicator(api, snapshot)

        return
    }

    if (intent.kind === 'page') {
        playPage(api, snapshot, intent.direction)

        return
    }

    playFilter(api, snapshot)
}

/* --- Sort ---------------------------------------------------------------- */

/**
 * The rows travel to their new positions.
 *
 * `absolute` is off, and has to be: taking a `<tr>` out of flow collapses the
 * table around it. Without it FLIP animates pure transforms and the rows pass
 * through each other on the way, which on a stack of same-height rows is
 * invisible -- and it keeps every write on the compositor.
 *
 * A transform on a row makes it the containing block for any fixed-position
 * descendant, so `clearProps` on completion is not tidiness, it is the reason
 * a row action modal still opens against the viewport afterwards.
 */
function playSort(api, snapshot) {
    const { gsap, MOTION, owned } = api

    const ctn = liveContainer(snapshot)

    if (!ctn) {
        return
    }

    const rows = recordNodes(ctn)

    if (!rows.length) {
        return
    }

    const known = snapshot.keys
    const moved = known ? rows.filter((row) => known.has(row.getAttribute('wire:key'))) : []
    const fresh = known ? rows.filter((row) => !known.has(row.getAttribute('wire:key'))) : []

    if (snapshot.state && moved.length) {
        owned(() =>
            Flip.from(snapshot.state, {
                duration: MOTION.base,
                ease: MOTION.ease,
                // A total amount rather than a per-row delay: ten rows and fifty
                // rows both finish rearranging in the same window.
                stagger: { amount: 0.12, from: 'start' },
                absolute: false,
                // No rotation or scale is involved, so FLIP can skip the matrix
                // decomposition it would otherwise do per element per frame.
                simple: true,
                onComplete: () => gsap.set(moved, { clearProps: 'transform,willChange' }),
            }),
        )
    }

    if (fresh.length) {
        enterRows(api, fresh)
    }
}

/**
 * The header says which way it went: the chevron spins into the direction it
 * now reports, and on a change of column the accent rule wipes across the new
 * one.
 *
 * The chevron's `transform` is safe to drive here. Filament puts a
 * `transition duration-75` shorthand on `.fi-ta-header-cell .fi-icon`, which
 * would re-time every frame GSAP wrote -- but motion.css replaces that with
 * `transition: translate`, so `transform` is unclaimed and the two layers
 * compose instead of fighting.
 */
function playSortIndicator(api, snapshot) {
    const { gsap, MOTION, owned } = api

    const th = sortedHeader(snapshot.ta)

    if (!th) {
        // The third click clears the sort entirely. Nothing to point at.
        return
    }

    if (th !== snapshot.sortedTh) {
        // A different column now owns the sort. Morph patches attributes in
        // place, so the node identity above is a reliable "did the column
        // change", and the wipe is reserved for when it did -- re-drawing the
        // same rule for an asc/desc toggle would be motion with nothing to say.
        owned(() =>
            gsap.fromTo(
                th,
                { '--vf-ta-sorted': 0 },
                {
                    '--vf-ta-sorted': 1,
                    duration: MOTION.base,
                    ease: MOTION.ease,
                    overwrite: 'auto',
                    // Removed rather than left at 1: the stylesheet's own
                    // default is 1, so handing the property back is what keeps
                    // the marker correct if this module is ever torn down.
                    onComplete: () => th.style.removeProperty('--vf-ta-sorted'),
                },
            ),
        )
    }

    // Filament renders TWO `.fi-icon` elements inside the sort control: the
    // chevron, and the spinner that replaces it while the request is out
    // (vendor/filament/support/src/helpers.php:226 gives the latter
    // `fi-loading-indicator`). Taking the first match would animate whichever
    // one happened to be showing.
    const icon = th.querySelector('.fi-ta-header-cell-sort-btn .fi-icon:not(.fi-loading-indicator)')

    if (!icon) {
        return
    }

    const ascending = th.getAttribute('aria-sort') === 'ascending'

    owned(() =>
        gsap.fromTo(
            icon,
            { rotation: ascending ? -180 : 180, scale: 0.65 },
            {
                rotation: 0,
                scale: 1,
                duration: MOTION.base,
                ease: MOTION.spring,
                overwrite: 'auto',
                clearProps: 'transform,willChange',
            },
        ),
    )
}

/* --- Pagination ---------------------------------------------------------- */

/**
 * The outgoing page starts leaving the moment the request goes out, so the turn
 * begins on the click rather than on the response.
 *
 * It dims to 0.35 rather than to nothing. The operator asked for a different
 * page, but until it arrives the page they are on is still the only data on
 * screen, and a blank table for the length of a round trip is worse than a
 * faded one. The guard restores it outright if the response is slow.
 */
function leadOut(api, ta, direction) {
    const { gsap, owned } = api

    const body = contentBody(ta)

    if (!body) {
        return
    }

    const sign = api.rtl ? -direction : direction

    owned(() =>
        gsap.to(body, {
            x: -14 * sign,
            opacity: 0.35,
            duration: 0.22,
            ease: 'power2.in',
            overwrite: 'auto',
        }),
    )

    armGuard(api, body, [body], VISIBILITY_GUARD)
}

/**
 * The new page arrives from the direction of travel, and the paginator's own
 * "1-25 of 4 218" line refreshes with it.
 */
function playPage(api, snapshot, direction) {
    const { gsap, MOTION, owned } = api

    const body = contentBody(snapshot.ta)

    if (!body) {
        return
    }

    // The lead-out's guard would otherwise fire in the middle of this and snap
    // the arrival flat.
    disarmGuard(body)

    const sign = api.rtl ? -direction : direction

    owned(() =>
        gsap.fromTo(
            body,
            { x: 26 * sign, opacity: 0 },
            {
                x: 0,
                opacity: 1,
                duration: MOTION.base,
                ease: MOTION.ease,
                overwrite: 'auto',
                clearProps: 'opacity,transform,willChange',
            },
        ),
    )

    armGuard(api, body, [body], VISIBILITY_GUARD)

    const overview = snapshot.ta.querySelector('.fi-pagination-overview')

    if (overview) {
        owned(() =>
            gsap.fromTo(
                overview,
                { y: -5 * sign, opacity: 0.4 },
                {
                    y: 0,
                    opacity: 1,
                    duration: MOTION.fast * 1.5,
                    ease: MOTION.easeSoft,
                    overwrite: 'auto',
                    clearProps: 'opacity,transform,willChange',
                },
            ),
        )

        armGuard(api, overview, [overview], VISIBILITY_GUARD)
    }
}

/* --- Filters ------------------------------------------------------------- */

/**
 * A filter changes how many rows there are, which changes the height of the
 * card, which moves everything below it. The container eases between the two
 * heights instead of snapping.
 *
 * `height` is a layout property, which everything else in this motion layer
 * avoids -- justified here because it is one element, for under two thirds of a
 * second, in direct response to a click, and there is no compositor-only way to
 * make the page below a shrinking card follow it honestly.
 *
 * `overflow-y` is pinned for the length of the tween because `.fi-ta-content-ctn`
 * carries `overflow-x: auto`, which per spec computes `overflow-y` to `auto` as
 * well -- constraining the height without this would flash a vertical scrollbar.
 * Setting only the Y axis leaves horizontal scrolling, and its scroll position,
 * untouched.
 */
function playFilter(api, snapshot) {
    const { gsap, MOTION, owned } = api

    const ctn = liveContainer(snapshot)

    if (!ctn) {
        return
    }

    const before = snapshot.height
    const after = ctn.offsetHeight

    if (before && after && Math.abs(after - before) > HEIGHT_THRESHOLD) {
        owned(() => {
            gsap.set(ctn, { height: before, overflowY: 'hidden' })

            return gsap.to(ctn, {
                height: after,
                duration: MOTION.base,
                ease: MOTION.easeSoft,
                overwrite: 'auto',
                clearProps: 'height,overflowY',
            })
        })

        // A height left pinned on the container would clip every row the next
        // poll adds. clearProps on the tween is the normal path; this is what
        // covers the tween being killed by a navigation or a second filter.
        owned(() =>
            gsap.delayedCall(MOTION.base + 0.6, () => {
                if (ctn.isConnected && !gsap.isTweening(ctn)) {
                    gsap.set(ctn, { clearProps: 'height,overflowY' })
                }
            }),
        )
    }

    const known = snapshot.keys
    const rows = recordNodes(ctn)
    const fresh = known ? rows.filter((row) => !known.has(row.getAttribute('wire:key'))) : rows

    if (fresh.length) {
        enterRows(api, fresh)
    }

    // The chips that say which filters are on. They are the direct answer to the
    // click, so they get the one piece of spring in this file that is not
    // reporting a measurement -- but only when they actually changed. Re-popping
    // an unchanged row of chips would be motion celebrating nothing.
    if (indicatorSignature(snapshot.ta) !== snapshot.indicators) {
        const badges = Array.from(
            snapshot.ta.querySelectorAll(
                '.fi-ta-filter-indicators .fi-ta-filter-indicators-badges-ctn .fi-badge',
            ),
        ).slice(0, 12)

        if (!badges.length) {
            return
        }

        owned(() =>
            gsap.fromTo(
                badges,
                { scale: 0.72, opacity: 0 },
                {
                    scale: 1,
                    opacity: 1,
                    duration: MOTION.fast * 1.6,
                    ease: MOTION.spring,
                    stagger: { amount: 0.12, from: 'start' },
                    overwrite: 'auto',
                    clearProps: 'opacity,transform,willChange',
                },
            ),
        )

        armGuard(api, badges[0], badges, VISIBILITY_GUARD)
    }
}

/* --- Shared -------------------------------------------------------------- */

/**
 * The applied-filter chips, as a string. Compared before and after so the pop
 * below only fires when the set of filters really changed -- text, not node
 * identity, because the morph rebuilds these chips wholesale every time.
 */
function indicatorSignature(ta) {
    const badges = ta.querySelectorAll(
        '.fi-ta-filter-indicators .fi-ta-filter-indicators-badges-ctn .fi-badge',
    )

    return Array.from(badges)
        .map((badge) => (badge.textContent || '').trim())
        .join('|')
}

/**
 * The content container as it exists NOW. Livewire's morph reuses the node in
 * every case observed, but a re-keyed table would replace it, and dereferencing
 * a detached node is the one failure this file cannot recover from.
 */
function liveContainer(snapshot) {
    if (snapshot.ctn && snapshot.ctn.isConnected) {
        return snapshot.ctn
    }

    return snapshot.ta.querySelector('.fi-ta-content-ctn')
}

/**
 * Rows that were not on screen a moment ago arrive.
 *
 * This is the one place rows are animated at all, and it is only ever reached
 * from a sort or a filter -- never from the poll that rebuilds these same rows
 * every 10 to 60 seconds. The stagger is bounded as a total amount so a page of
 * fifty records finishes in the same window as a page of ten, and every row is
 * guarded back to full opacity regardless of what the tween does.
 */
function enterRows(api, rows) {
    const { gsap, MOTION, owned } = api

    const batch = rows.slice(0, MAX_ENTER_ROWS)

    owned(() =>
        gsap.fromTo(
            batch,
            { opacity: 0, y: 9 },
            {
                opacity: 1,
                y: 0,
                duration: MOTION.fast * 1.5,
                ease: MOTION.easeSoft,
                stagger: { amount: Math.min(0.26, batch.length * 0.014), from: 'start' },
                overwrite: 'auto',
                clearProps: 'opacity,transform,willChange',
            },
        ),
    )

    // One guard for the whole batch rather than one per row: forty scheduled
    // callbacks to protect forty rows that share a single tween is a cost with
    // no matching benefit.
    armGuard(api, batch[0], batch, VISIBILITY_GUARD)
}

/* ------------------------------------------------------------------------- */
/* Column header affordance                                                   */
/* ------------------------------------------------------------------------- */

/**
 * What a sortable column does under the pointer.
 *
 * Delegated, like everything else in this panel that touches a table: rows and
 * header cells are re-created on every poll, so a listener bound to a cell would
 * be a dead closure within the minute. One `pointerover` pair on `document`
 * covers header cells that do not exist yet and cannot leak.
 *
 * Deliberately says nothing about DIRECTION. A preview that flips the chevron to
 * where the next click would take it is a lovely trick and a bad idea on a panel
 * where the current sort is information someone is acting on -- for the half
 * second the cursor rests there, the header would be reporting a sort that is
 * not in effect. The underline says "this sorts"; the chevron keeps saying what
 * is true.
 */
function headerAffordance(api, scope) {
    const { gsap, MOTION, owned } = api

    // Hover work only. On a touch device there is no cursor to react to, so this
    // would be a pair of listeners riding along on a phone for nothing.
    const mm = gsap.matchMedia()
    scope.add(() => mm.revert())

    mm.add('(pointer: fine)', () => {
        const fine = createScope()
        let hot = null

        const enter = (button) => {
            if (isDisabled(button)) {
                return
            }

            const cell = button.closest('.fi-ta-header-cell')

            if (cell) {
                owned(() =>
                    gsap.to(cell, {
                        '--vf-ta-hover': 1,
                        duration: MOTION.fast * 1.3,
                        ease: MOTION.ease,
                        overwrite: 'auto',
                    }),
                )
            }

            const icon = button.querySelector('.fi-icon:not(.fi-loading-indicator)')

            if (icon) {
                owned(() =>
                    gsap.to(icon, {
                        scale: 1.18,
                        duration: MOTION.fast,
                        ease: MOTION.spring,
                        overwrite: 'auto',
                    }),
                )
            }
        }

        const leave = (button) => {
            const cell = button.closest('.fi-ta-header-cell')

            if (cell) {
                owned(() =>
                    gsap.to(cell, {
                        '--vf-ta-hover': 0,
                        duration: MOTION.fast,
                        ease: MOTION.easeSoft,
                        overwrite: 'auto',
                        // Handed back to the stylesheet rather than left at an
                        // inline 0, so nothing this module wrote outlives it.
                        onComplete: () => cell.style.removeProperty('--vf-ta-hover'),
                    }),
                )
            }

            const icon = button.querySelector('.fi-icon:not(.fi-loading-indicator)')

            if (icon) {
                owned(() =>
                    gsap.to(icon, {
                        scale: 1,
                        duration: MOTION.fast,
                        ease: MOTION.spring,
                        overwrite: 'auto',
                        clearProps: 'transform,willChange',
                    }),
                )
            }
        }

        fine.on(
            document,
            'pointerover',
            (event) => {
                // A pen behaves like a mouse; a finger has no hover state at all
                // and would leave a header lit until the next poll replaced it.
                if (event.pointerType === 'touch') {
                    return
                }

                const target = event.target

                if (!target || typeof target.closest !== 'function') {
                    return
                }

                const button = target.closest('.fi-ta-header-cell-sort-btn')

                if (button === hot) {
                    return
                }

                if (hot) {
                    leave(hot)
                }

                hot = button

                if (hot) {
                    enter(hot)
                }
            },
            { passive: true },
        )

        // Leaving the document entirely never produces a pointerover for
        // anything else, so nothing would ever be released.
        fine.on(
            document,
            'pointerout',
            (event) => {
                if (event.relatedTarget || !hot) {
                    return
                }

                leave(hot)
                hot = null
            },
            { passive: true },
        )

        fine.on(window, 'blur', () => {
            if (!hot) {
                return
            }

            leave(hot)
            hot = null
        })

        return () => {
            // The settle-back tweens are skipped on teardown on purpose: creating
            // tweens while the runtime's context is mid-revert leaves them
            // running after the context has stopped tracking them, and the
            // stylesheet they animate against is about to be removed anyway.
            hot = null
            fine.dispose()
        }
    })
}

function isDisabled(element) {
    return (
        element.hasAttribute('disabled') ||
        element.getAttribute('aria-disabled') === 'true' ||
        element.classList.contains('fi-disabled')
    )
}
