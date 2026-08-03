/*
 * SPA page transitions -- the exit side, and the thread between two pages.
 * ------------------------------------------------------------------------
 * Filament navigates with `wire:navigate`: Alpine fetches the next page and
 * then does `document.body.replaceWith(newBody)`. Nothing reloads, so the panel
 * gets a real chance to make a page change read as one continuous movement
 * instead of a hard cut. entrance.js already owns the arrival. This module owns
 * everything before and around it:
 *
 *   - the EXIT: content falls back and dims while the chrome stays put;
 *   - the DIRECTION: going deeper looks different from coming back out;
 *   - the CARRY: the label you clicked flies up and becomes the next page's
 *     heading, which is the single thing that makes two pages feel like one
 *     surface rather than two documents;
 *   - the HOLD: what the outgoing page does when the network is slow, and the
 *     failsafes that put it back if the navigation never lands.
 *
 * The event sequence this hangs off, read out of Alpine's navigate plugin
 * (vendor/livewire/livewire/dist/livewire.esm.js, `navigateTo`):
 *
 *   livewire:navigate   at click, BEFORE the fetch. Cancelable, and Filament
 *                       really does cancel it -- the unsaved-changes guard
 *                       calls preventDefault() on any page with a dirty form.
 *                       detail = { url: URL, history: bool, cached: bool }.
 *   livewire:navigating the HTML is in hand and the swap is the next statement.
 *   livewire:navigated  the new body is live; the runtime re-boots every module.
 *
 * The exit therefore gets exactly one window: between `navigate` and
 * `navigating`, which is however long the fetch takes and is ZERO for a page
 * Alpine prefetched on hover. That is the right shape anyway -- the exit is
 * allowed to be cut off by the swap, and it must never be the reason an
 * operator waits. Everything here lands in ~260ms.
 *
 * Four constraints shape the rest:
 *
 * 1. NOTHING MAY BE LEFT UNREADABLE. The exit dims content to 22%, not to zero,
 *    and if the navigation has not landed within 450ms the page comes part-way
 *    back and breathes -- so a stalled fetch leaves a legible page, not a blank
 *    one. A hard failsafe restores it outright at 3s, and a cancelled
 *    navigation restores it immediately (see `livewire:navigate` below).
 *
 * 2. NO SECOND PROGRESS BAR. The brief suggested one; the panel already has
 *    two. Livewire ships nprogress on every `wire:navigate` (2px, fixed to the
 *    top, coloured with `--livewire-progress-bar-color`, which
 *    vendor/filament/filament/resources/views/components/layout/base.blade.php
 *    sets to `--primary-500`), and scroll.js draws its own 2px reading-progress
 *    hairline in the same place. A third would be noise. The slow-navigation
 *    signal here is the hold, which costs no DOM at all.
 *
 * 3. TRANSFORMS STAY TRANSIENT. Filament renders action modals (`position:
 *    fixed`) inline inside sections and inside `.fi-page`, and an element
 *    holding a transform becomes the containing block for its fixed
 *    descendants. Every transform written here either dies with the page it is
 *    on or is removed by `clearProps` when its tween lands, and both the exit
 *    and the arrival bias step aside entirely while a modal is open
 *    (`.fi-modal-open`, set by Alpine on the modal root).
 *
 * 4. THE CHROME DOES NOT MOVE. The topbar and the sidebar are the same objects
 *    before and after -- animating them would say something untrue. Only the
 *    page header and the page content leave.
 *
 * Cleanup: the runtime's gsap.context() reverts tweens but not listeners, and
 * it cannot delete the ghost element this file appends. Each run therefore
 * undoes the previous one first, and a nested context registers the same
 * teardown so that reverting (a reduced-motion toggle, which re-boots without
 * running any module) also unwinds it.
 */

import { CustomEase } from 'gsap/CustomEase'

/* Marks DOM this module created, so a run can sweep the previous run's. */
const OWNED_ATTR = 'data-vf-transition'

/*
 * The exit ease, mirroring `--vf-ease-in` in motion.css so the JS and CSS
 * layers cannot drift apart -- cubic-bezier(x1,y1,x2,y2) is the SVG path
 * `M0,0 C x1,y1 x2,y2 1,1`. It accelerates: the content holds still for a beat
 * and then goes, which over 220ms reads as decisive rather than as a fade.
 * MOTION ships only *.out eases, and an exit on an out-ease reads as the page
 * being reluctant to leave.
 */
const EXIT_EASE = 'vfPageExit'
const EXIT_EASE_PATH = 'M0,0 C0.5,0 0.9,0.2 1,1'

const EXIT_DURATION = 0.2
/* Total spread across every block, not per block: a dashboard with three
   sections and a log page with twelve both leave in the same window. */
const EXIT_SPREAD = 0.06
/* Where the outgoing content settles. Not 0: the only thing that ever sees
   this state for longer than a blink is a navigation that failed, and a page
   at 22% still tells an operator where they are. */
const EXIT_ALPHA = 0.22

/* Seconds after the exit starts before the page decides the network is slow,
   comes part-way back and breathes there. */
const HOLD_AT = 0.45
const HOLD_ALPHA = 0.62

/* Seconds after which a navigation that never produced `livewire:navigating`
   is written off and the page is handed back in full. */
const GIVE_UP_AT = 3

/* Blocks the exit is allowed to animate. Past this it is a list, not a
   composition, and each extra element is another compositor layer. */
const MAX_EXIT_BLOCKS = 10

/* Carry candidates captured per click, and the longest label worth carrying.
   A heading longer than this is a sentence and would fly across the viewport. */
const MAX_LABELS = 12
const MAX_LABEL_CHARS = 64

/* How long a captured click stays eligible to seed a carry. Longer than any
   navigation this panel performs, short enough that a click abandoned minutes
   ago cannot seed a ghost on some later page. */
const CLICK_TTL = 5000

/* ------------------------------------------------------------------------- */
/* Module-scope state                                                         */
/*                                                                            */
/* The runtime re-invokes this function on every navigation but never          */
/* re-imports the module, so module scope is the only thing that survives a    */
/* body swap -- which is exactly what a transition needs: the outgoing page    */
/* writes here, the incoming page reads.                                      */
/* ------------------------------------------------------------------------- */

let disposers = []

/* Registered once per document, not once per run. */
let easeRegistered = false

/* The last control an operator activated, with its text labels measured. Null
   whenever the last thing they touched could not start a navigation. */
let activation = null

/* Handed from the outgoing page to the incoming one: which way we went, and
   which label (if any) should fly into the new heading. */
let handoff = null

/* The nodes currently held in the exit state, so a cancelled or stalled
   navigation knows what to put back. */
let exiting = null

function runDisposers() {
    while (disposers.length) {
        const dispose = disposers.pop()

        try {
            dispose()
        } catch (error) {
            // A failing disposer must not strand the ones behind it.
            console.warn('[vpn-forge motion] transitions cleanup failed', error)
        }
    }
}

/* ------------------------------------------------------------------------- */
/* Pure helpers                                                               */
/* ------------------------------------------------------------------------- */

/** Compare-ready form of a visible string: one space between words, no case. */
function normalise(text) {
    return String(text || '')
        .replace(/\s+/g, ' ')
        .trim()
        .toLowerCase()
}

function segmentsOf(pathname) {
    return String(pathname || '')
        .split('/')
        .filter(Boolean)
}

function startsWithPath(outer, inner) {
    for (let i = 0; i < inner.length; i += 1) {
        if (outer[i] !== inner[i]) {
            return false
        }
    }

    return true
}

/**
 * Which way this navigation goes: 1 deeper, -1 back out, 0 sideways.
 *
 * Path shape is the honest signal -- /admin/servers -> /admin/servers/3/edit is
 * unambiguously deeper, and the reverse is unambiguously back. A breadcrumb
 * click is forced backwards regardless, because that is what a breadcrumb means
 * even when the URLs do not nest. The browser's own back/forward button is
 * always backwards.
 *
 * @param {?URL} url  destination, as Alpine hands it over
 * @param {boolean} isHistory  true for a popstate navigation
 * @param {boolean} fromBreadcrumb
 * @returns {number}
 */
function directionFor(url, isHistory, fromBreadcrumb) {
    if (isHistory || fromBreadcrumb) {
        return -1
    }

    if (!url || typeof url.pathname !== 'string') {
        return 0
    }

    const from = segmentsOf(window.location.pathname)
    const to = segmentsOf(url.pathname)

    if (to.length === from.length) {
        return 0
    }

    if (to.length > from.length) {
        return startsWithPath(to, from) ? 1 : 0
    }

    // Shorter destination: either a genuine step back up the same branch, or a
    // jump from somewhere deep to a top-level page. Both read as coming out.
    return -1
}

/**
 * The page blocks that leave. Deliberately the page HEADER plus the top-level
 * children of `.fi-page-content` -- the same partition entrance.js arrives on,
 * so an exit and the next entrance are mirror images of each other rather than
 * two unrelated effects.
 *
 * Off-screen blocks are skipped: they are not visible, so moving them buys
 * nothing and costs a compositor layer each. Every measurement happens here,
 * before the caller writes anything.
 *
 * @returns {HTMLElement[]}
 */
function exitTargets() {
    const main = document.querySelector('.fi-main')

    if (!main) {
        return []
    }

    const targets = []
    const header = main.querySelector('.fi-header')

    if (header) {
        targets.push(header)
    }

    const content = main.querySelector('.fi-page-content')
    const height = window.innerHeight || document.documentElement.clientHeight || 0

    if (content && height) {
        for (const block of content.children) {
            if (targets.length >= MAX_EXIT_BLOCKS) {
                break
            }

            const rect = block.getBoundingClientRect()

            // Zero-sized nodes are Livewire's empty render-hook wrappers.
            if (!rect.width && !rect.height) {
                continue
            }

            if (rect.top > height || rect.bottom < 0) {
                continue
            }

            targets.push(block)
        }
    }

    if (targets.length) {
        return targets
    }

    // A page with neither header nor content still deserves an exit; the whole
    // page wrapper is the coarsest version of the same gesture.
    const fallback = main.querySelector('.fi-page') || main

    return fallback ? [fallback] : []
}

/**
 * Measure every short text label inside the control that was just activated.
 *
 * Several candidates rather than one guess: which of them (if any) turns into
 * the next page's heading is only knowable once that page exists, so the
 * decision is deferred and made by string equality on the other side. Clicking
 * a sidebar item offers its label; clicking a table row offers every cell it
 * contains. The source node itself cannot be kept -- `document.body
 * .replaceWith()` destroys it -- so everything the carry will need (box,
 * colour, size) is read now.
 *
 * @param {Element} anchor
 * @returns {Array<object>}
 */
function labelsInside(anchor) {
    const labels = []
    const seen = new Set()
    const height = window.innerHeight || document.documentElement.clientHeight || 0

    const consider = (element) => {
        if (labels.length >= MAX_LABELS) {
            return
        }

        const text = normalise(element.textContent)

        if (!text || text.length > MAX_LABEL_CHARS || seen.has(text)) {
            return
        }

        const rect = element.getBoundingClientRect()

        // Too small to have been read, or off screen: carrying it would look
        // like a label flying in from nowhere.
        if (rect.width < 8 || rect.height < 6) {
            return
        }

        if (height && (rect.bottom < 0 || rect.top > height)) {
            return
        }

        const style = window.getComputedStyle(element)

        seen.add(text)
        labels.push({
            text,
            top: rect.top,
            left: rect.left,
            height: rect.height,
            fontSize: parseFloat(style.fontSize) || 0,
            color: style.color,
        })
    }

    /*
     * Leaves first, container last. An ancestor and its single text child hold
     * the same string, and `seen` keeps whichever arrives first -- so the order
     * decides whether the carry starts from the glyphs or from the padded box
     * around them. A sidebar item's <a> is 220px wide with an icon in it; the
     * <span> inside wraps the label exactly, and that is the box the label
     * should appear to leave from.
     */
    const inside = anchor.querySelectorAll('*')

    for (let i = 0; i < inside.length && i < 60; i += 1) {
        if (!inside[i].firstElementChild) {
            consider(inside[i])
        }
    }

    consider(anchor)

    return labels
}

/* ------------------------------------------------------------------------- */

/**
 * SPA page transitions.
 *
 * @param {{gsap: import('gsap').gsap, ScrollTrigger: object, MOTION: object}} api
 * @returns {null}
 */
export function pageTransitions({ gsap, MOTION }) {
    if (typeof document === 'undefined' || !document.body) {
        return null
    }

    gsap.registerPlugin(CustomEase)

    if (!easeRegistered) {
        CustomEase.create(EXIT_EASE, EXIT_EASE_PATH)
        easeRegistered = true
    }

    // Undo the previous run before building anything: its listeners, its
    // timers, and any ghost still in flight when the next page arrived.
    runDisposers()
    document.querySelectorAll(`[${OWNED_ATTR}]`).forEach((node) => node.remove())

    /*
     * `gsap.context()` with no argument returns the context we are running
     * inside -- the runtime's. Tweens created later, from a navigation handler,
     * would otherwise belong to no context at all: nothing would revert them,
     * and a reduced-motion toggle mid-exit would leave the page dimmed. Routing
     * them through `add()` puts them back under the runtime's control.
     */
    const host = gsap.context()

    /* `context.add()` treats a returned function as a cleanup callback, so the
       result is fished out through a closure instead of returned. */
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

    const listen = (target, type, handler, options) => {
        if (!target || !target.addEventListener) {
            return
        }

        target.addEventListener(type, handler, options)
        disposers.push(() => target.removeEventListener(type, handler, options))
    }

    // gsap.context() reverts tweens, not listeners. This nested context exists
    // only so the runtime's revert() reaches the teardown above.
    gsap.context(() => () => runDisposers())

    /* Timers and the hold loop, kept so a navigation that lands can cancel the
       failsafes it no longer needs. */
    let holdCall = null
    let giveUpCall = null
    let holding = null

    function clearGuards() {
        if (holdCall) {
            holdCall.kill()
            holdCall = null
        }

        if (giveUpCall) {
            giveUpCall.kill()
            giveUpCall = null
        }

        if (holding) {
            holding.kill()
            holding = null
        }
    }

    disposers.push(clearGuards)

    /* --------------------------------------------------------------------- *
     * Exit
     * --------------------------------------------------------------------- */

    /**
     * Content falls back and dims; the chrome stays exactly where it is.
     *
     * @param {number} direction  1 deeper, -1 back out, 0 sideways
     */
    function playExit(direction) {
        const targets = exiting || exitTargets()

        if (!targets.length) {
            return
        }

        exiting = targets

        const vars = {
            opacity: EXIT_ALPHA,
            duration: EXIT_DURATION,
            ease: EXIT_EASE,
            overwrite: 'auto',
            // Going deeper the page peels off the top down, coming back out it
            // peels off the bottom up -- the same order the eye expects to
            // travel in each case.
            stagger: { amount: EXIT_SPREAD, from: direction < 0 ? 'end' : 'start' },
        }

        // With a modal open, every transform is off the table: the modal is
        // `position: fixed` inside the very blocks being animated, and a
        // transform on an ancestor re-anchors it to that block. The dim alone
        // still reads as an exit.
        if (!document.querySelector('.fi-modal-open')) {
            // Deeper: the page recedes and slides against the incoming one.
            // Back: it advances and slides the other way, as though you had
            // passed through it.
            vars.x = direction > 0 ? -12 : direction < 0 ? 16 : 0
            vars.y = direction < 0 ? -6 : 10
            vars.scale = direction < 0 ? 1.012 : 0.986
            // From the top edge, so a tall block does not appear to slide
            // upward as it shrinks.
            vars.transformOrigin = '50% 0%'
        }

        owned(() => {
            // motion.css transitions box-shadow on `.fi-section`; nothing there
            // transitions opacity or transform today. Suppressed anyway so this
            // stays true if the CSS layer grows -- a transition on a property
            // GSAP is writing re-eases every frame and smears the tween.
            gsap.set(targets, { willChange: 'transform, opacity', transition: 'none' })

            return gsap.to(targets, vars)
        })
    }

    /**
     * The network is slow. Bring the page part-way back and breathe there.
     *
     * This is the slow-navigation signal, in place of a third progress bar (see
     * the file header). It also does the safety work: within ~1s of the click a
     * stalled page is legible again instead of sitting at 22% until the hard
     * failsafe fires.
     */
    function playHold() {
        if (!exiting || !exiting.length) {
            return
        }

        const targets = exiting

        holding = owned(() =>
            gsap
                .timeline()
                .to(targets, {
                    opacity: HOLD_ALPHA,
                    duration: 0.4,
                    ease: MOTION.easeSoft,
                    overwrite: 'auto',
                })
                .to(targets, {
                    opacity: HOLD_ALPHA * 0.72,
                    duration: 0.9,
                    ease: 'sine.inOut',
                    repeat: -1,
                    yoyo: true,
                }),
        )
    }

    /**
     * Hand the page back. Called when a navigation is cancelled -- which is a
     * real, shipping path in this panel: Filament's unsaved-changes guard calls
     * preventDefault() on `livewire:navigate` for any page with a dirty form --
     * and by the hard failsafe when a navigation simply never arrives.
     *
     * @param {boolean} instant
     */
    function restore(instant) {
        clearGuards()

        const targets = exiting

        exiting = null

        // A no-op when nothing is held. The hard failsafe fires on a timer that
        // a completed navigation may already have made irrelevant, and it must
        // not reach in and drop the handoff a later click has since written.
        if (!targets || !targets.length) {
            return
        }

        handoff = null

        owned(() =>
            gsap.to(targets, {
                opacity: 1,
                x: 0,
                y: 0,
                scale: 1,
                duration: instant ? 0.12 : 0.24,
                ease: MOTION.easeSoft,
                overwrite: true,
                // Leave no residue: the blocks end up exactly as the server
                // rendered them, so hover states and Filament's own Alpine
                // styling keep working on a page that never went anywhere.
                clearProps: 'opacity,transform,transformOrigin,willChange,transition',
            }),
        )
    }

    /* --------------------------------------------------------------------- *
     * Arrival: the thread from the page that just left
     * --------------------------------------------------------------------- */

    /**
     * A whole-column slide in the direction the operator travelled, underneath
     * entrance.js's per-block choreography. Small and fast on purpose -- it
     * reads as parallax behind the arrival, not as a second animation.
     *
     * Horizontal only, for two reasons. `.fi-layout` is `overflow-x: clip`
     * (vendor/filament/filament/resources/css/components/layout.css), so a
     * sideways transform cannot produce a scrollbar; a vertical one would have
     * no such guard. And ScrollTrigger measures with getBoundingClientRect, so
     * modules that build after this one -- scroll.js, counters.js -- would read
     * every trigger position off by whatever vertical offset was held here.
     *
     * @param {number} direction
     */
    function arrivalBias(direction) {
        if (!direction) {
            return
        }

        const main = document.querySelector('.fi-main')

        if (!main) {
            return
        }

        // A transform makes this the containing block for the fixed-position
        // modals Filament renders inside it. It is cleared when the tween
        // lands, but a modal that is already open cannot wait 0.5s for that.
        if (document.querySelector('.fi-modal-open')) {
            return
        }

        gsap.set(main, { willChange: 'transform' })
        gsap.fromTo(
            main,
            { x: direction > 0 ? 16 : -16 },
            {
                x: 0,
                duration: 0.55,
                ease: MOTION.ease,
                clearProps: 'transform,willChange',
            },
        )
    }

    /**
     * Carry the label that was clicked into the heading it became.
     *
     * The shared element cannot be FLIPped: `document.body.replaceWith()` has
     * already destroyed the node that was clicked, so there is no state to
     * restore from and no element to fit. What survives is the measurement
     * taken at click time, so the carry is drawn instead -- one span, styled
     * with the destination heading's own type so it lands invisibly, filled
     * with the source's colour so it leaves recognisably, flown between the two
     * boxes and cross-faded into the real heading as entrance.js resolves it.
     *
     * The heading itself is never touched. entrance.js owns it, and two tweens
     * on one transform is how a heading ends up frozen at 40% opacity.
     *
     * @param {Array<object>} labels  candidates measured at click time
     * @returns {boolean}  whether a carry was started
     */
    function playCarry(labels) {
        if (!labels || !labels.length) {
            return false
        }

        const heading = document.querySelector('.fi-main .fi-header-heading')

        if (!heading) {
            return false
        }

        const text = normalise(heading.textContent)

        if (!text || text.length > MAX_LABEL_CHARS) {
            return false
        }

        // Strict equality. A partial match ("web-01" inside "Modifier web-01")
        // would fly a fragment into the middle of a longer title.
        const source = labels.find((label) => label.text === text)

        if (!source || !source.fontSize) {
            return false
        }

        const style = window.getComputedStyle(heading)
        const fontSize = parseFloat(style.fontSize) || 0

        if (!fontSize) {
            return false
        }

        const rect = heading.getBoundingClientRect()

        if (!rect.width || !rect.height) {
            return false
        }

        // The ghost is `white-space: nowrap`, so a heading that wraps would
        // land as one long line over two short ones.
        if (rect.height > fontSize * 2.2) {
            return false
        }

        /*
         * entrance.js creates its heading tween with fromTo, which renders its
         * from-values immediately -- so by the time this runs the heading is
         * already sitting 24px below where it will end up. The carry has to
         * land on the RESTING position, so whatever transform is currently held
         * is subtracted back out.
         */
        // parseFloat, not Number: getProperty hands transform values back with
        // their unit ("24px"), and Number() would quietly turn that into NaN
        // and drop the compensation altogether.
        const restingTop = rect.top - (parseFloat(gsap.getProperty(heading, 'y')) || 0)
        const restingLeft = rect.left - (parseFloat(gsap.getProperty(heading, 'x')) || 0)

        const scale = source.fontSize / fontSize
        const dx = source.left - restingLeft
        // Centres, not top edges: the source is a tight text box and the
        // heading has leading above its glyphs, so aligning the tops would drop
        // the ghost visibly as it landed.
        const dy = source.top + source.height / 2 - (restingTop + rect.height / 2)

        // Nothing to carry: same place, same size.
        if (Math.abs(dx) < 6 && Math.abs(dy) < 6 && Math.abs(scale - 1) < 0.06) {
            return false
        }

        const ghost = document.createElement('span')
        ghost.setAttribute(OWNED_ATTR, 'carry')
        ghost.setAttribute('aria-hidden', 'true')
        ghost.textContent = String(heading.textContent || '').trim()
        ghost.style.cssText = [
            'position:fixed',
            `top:${restingTop}px`,
            `left:${restingLeft}px`,
            'margin:0',
            'padding:0',
            'white-space:nowrap',
            'pointer-events:none',
            // Above the sticky topbar (30) and scroll.js's hairline (35),
            // below Filament's modal overlay (40).
            'z-index:36',
            `color:${source.color}`,
            `font-family:${style.fontFamily}`,
            `font-size:${style.fontSize}`,
            `font-weight:${style.fontWeight}`,
            `font-style:${style.fontStyle}`,
            `letter-spacing:${style.letterSpacing}`,
            `line-height:${style.lineHeight}`,
            'will-change:transform, opacity',
        ].join(';')

        document.body.appendChild(ghost)

        // Two independent removals. The disposer covers the next navigation and
        // a context revert -- which would otherwise kill the tween and leave
        // the ghost frozen over the heading -- and the wall clock covers
        // everything else. A stranded ghost sits on top of the page title, so
        // its removal must not depend on any tween finishing.
        const remove = () => ghost.remove()
        disposers.push(remove)

        const timer = window.setTimeout(remove, 1600)
        disposers.push(() => window.clearTimeout(timer))

        gsap.timeline({ onComplete: remove })
            .fromTo(
                ghost,
                { x: dx, y: dy, scale, transformOrigin: '0% 50%' },
                { x: 0, y: 0, scale: 1, duration: 0.5, ease: MOTION.ease },
                0,
            )
            // The real heading is fading in underneath on entrance.js's own
            // ramp; this hands over to it rather than blinking out.
            .to(ghost, { opacity: 0, duration: 0.2, ease: 'power2.in' }, 0.3)

        return true
    }

    /* --------------------------------------------------------------------- *
     * Wiring
     * --------------------------------------------------------------------- */

    /*
     * Capture what was activated, before anything navigates. pointerdown beats
     * every mechanism Alpine uses to start a navigation, and keydown covers the
     * operator who never touches the mouse. `activation` is cleared when the
     * thing touched could not have started a navigation, so a stale label can
     * never seed a carry on an unrelated page.
     */
    const capture = (event) => {
        const target = event.target

        if (!target || typeof target.closest !== 'function') {
            return
        }

        const anchor = target.closest(
            'a[href], button, [role="button"], tr.fi-ta-row, .fi-ta-record',
        )

        if (!anchor) {
            activation = null

            return
        }

        activation = {
            at: Date.now(),
            // A breadcrumb is a statement about direction that the URLs do not
            // always make on their own.
            breadcrumb: Boolean(anchor.closest('.fi-breadcrumbs')),
            labels: labelsInside(anchor),
        }
    }

    listen(document, 'pointerdown', capture, { passive: true, capture: true })
    listen(
        document,
        'keydown',
        (event) => {
            if (event.key === 'Enter' || event.key === ' ') {
                capture(event)
            }
        },
        { passive: true, capture: true },
    )

    listen(document, 'livewire:navigate', (event) => {
        clearGuards()

        const detail = event.detail || {}
        const fresh = activation && Date.now() - activation.at < CLICK_TTL ? activation : null

        const direction = directionFor(
            detail.url,
            Boolean(detail.history),
            Boolean(fresh && fresh.breadcrumb),
        )

        handoff = { direction, labels: fresh ? fresh.labels : [] }
        activation = null

        playExit(direction)

        /*
         * Whether this navigation is actually going to happen is decided by the
         * listeners that run after this one -- Filament's unsaved-changes guard
         * preventDefault()s the event on any page with a dirty form. dispatch
         * is synchronous, so by the first microtask the verdict is final.
         */
        queueMicrotask(() => {
            if (event.defaultPrevented) {
                restore(true)

                return
            }

            holdCall = owned(() => gsap.delayedCall(HOLD_AT, playHold))
            giveUpCall = owned(() => gsap.delayedCall(GIVE_UP_AT, () => restore(false)))
        })
    })

    listen(document, 'livewire:navigating', () => {
        // The swap is the next statement Alpine runs, so the outgoing nodes are
        // about to stop existing. Drop the failsafes and let go of them --
        // restoring here would be work on a page nobody will ever see, and the
        // breathing loop would otherwise keep ticking on detached elements.
        clearGuards()
        exiting = null
    })

    /* --------------------------------------------------------------------- *
     * This run's arrival
     * --------------------------------------------------------------------- */

    // Null on a cold load, and null after a cancelled navigation -- both are
    // pages nobody travelled to, so neither gets a continuity effect.
    if (handoff) {
        const { direction, labels } = handoff
        handoff = null

        // The carry is the continuity, so it replaces the bias rather than
        // running alongside it: the ghost lands on the heading's resting
        // position, and a column still sliding underneath would put the two a
        // dozen pixels apart at exactly the moment they are meant to coincide.
        if (!playCarry(labels)) {
            arrivalBias(direction)
        }
    }

    return null
}
