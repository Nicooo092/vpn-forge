/**
 * The first-run journey.
 * ----------------------
 * `GettingStarted` renders four compact sections inside one widget: create a
 * service, set its public address, add a user, turn on 2FA. Each one shows its
 * own icon while it is pending and a green check once it is done, and the whole
 * widget deletes itself from the dashboard the moment the three essentials are
 * satisfied (see `GettingStarted::canView()`).
 *
 * As shipped, that reads as four unrelated cards that silently change colour
 * between visits. This module turns it into a route: a spine down the left of
 * the stack that is filled as far as the operator has actually got, a marker at
 * the head of the fill and a breath on the icon of the step they should do
 * next, and -- the moment that matters -- a check landing with a spring and the
 * line advancing past it when a step has been completed since last time.
 *
 * Four things shape the implementation.
 *
 * 1. THE WIDGET NEVER UPDATES IN PLACE. It has no poll, no actions, and every
 *    call to action navigates away. A step is therefore never seen changing:
 *    the operator leaves, does the work somewhere else, and comes back to a
 *    freshly rendered widget. The completion moment only exists if we remember
 *    what the checklist looked like last time, so the previous state is kept in
 *    `sessionStorage` -- per tab, cleared with it, and surviving a hard reload
 *    as well as an SPA hop, which a module-level variable would not.
 *
 * 2. THE DEPARTURE HAPPENS ON A PAGE THAT NO LONGER CONTAINS THE WIDGET. By the
 *    time the essentials are done the server has already stopped rendering it,
 *    so there is nothing left on the page to animate out. What we do have is the
 *    markup as it stood on the last visit; the farewell replays that snapshot as
 *    an inert overlay -- laid over the dashboard, never in its layout, so not a
 *    single pixel of the real page is reflowed -- runs the line to the end, and
 *    lifts it away. Nothing is fabricated: every word in it was rendered by the
 *    server, and the only new statement is the line finishing, which is exactly
 *    what "the checklist is over" means.
 *
 * 3. THE SPINE HAS TO LIVE IN A GUTTER, NOT ON TOP OF ANYTHING. It is measured
 *    into the gap between a step card's leading edge and its icon (`px-4` on a
 *    compact section header, so ~16px), and it is not drawn at all if that gap
 *    turns out to be too narrow to hold it clear of the icon. It never crosses
 *    text, and the layer it lives in is `pointer-events: none`, so the CTA
 *    buttons underneath keep working.
 *
 * 4. NO TRANSFORM IS LEFT ON ANYTHING THAT OWNS A CONTROL. Filament renders
 *    action modals inline, and a live transform on an ancestor would re-anchor
 *    a `position: fixed` modal to that ancestor. The only persistent transform
 *    here is on the `<svg>` icons, which contain nothing. The step column --
 *    the one ancestor of the CTA links this module touches -- gets `position`
 *    and nothing else, and `position: relative` is a containing block for
 *    absolute descendants only, never for fixed ones. Everything else (the
 *    check spring, the rings, the farewell) either strips its own transform on
 *    completion or lives on a node this module created.
 *
 * Cleanup is explicit and idempotent: every run removes the previous run's
 * nodes by attribute, runs its disposers, and disposes early when the SPA
 * starts navigating away.
 *
 * @param {{gsap: import('gsap').gsap, ScrollTrigger: object, MOTION: object}} api
 */

/* Marks every node this module appends. gsap.context() reverts tweens but has
   no idea we created DOM, so teardown is ours: each run sweeps by attribute
   first, which also collects orphans from a run that was interrupted. */
const OWNED = 'data-vf-onboarding'

/* Per-tab, so two windows cannot report each other's progress at each other. */
const STORE = 'vf-onboarding-journey'

/* A snapshot older than this is not a departure, it is a stale tab. Replaying
   it half an hour later would be the panel talking about something the operator
   has long since forgotten doing. */
const FAREWELL_TTL = 30 * 60 * 1000

/* Refuse to keep a snapshot past this. The widget is ~5KB of markup; anything
   an order of magnitude bigger means the view changed shape and the assumption
   that this is cheap to store no longer holds. */
const SNAPSHOT_LIMIT = 60000

/* Minimum space between a card's leading edge and its icon for the spine to fit
   clear of both. Below it, the journey runs without a line rather than drawing
   one through an icon. */
const MIN_GUTTER = 10

/* A stack shorter than this has no meaningful distance to fill. */
const MIN_SPAN = 12

/*
 * Filament's own palette variables, emitted inline on :root by FilamentAsset.
 * Green for ground covered, the panel's amber for where you are -- the same two
 * colours the checklist itself already uses for done and pending icons, so this
 * introduces no new vocabulary. Literals are the fallback for the instant
 * before that inline <style> lands.
 */
const TRACK_TINT = 'color-mix(in oklab, var(--gray-500, #6b7280) 24%, transparent)'
const DONE_TINT = 'var(--success-500, #22c55e)'
const DONE_HALO = 'color-mix(in oklab, var(--success-500, #22c55e) 60%, transparent)'
const NEXT_TINT = 'var(--primary-500, #f59e0b)'
const NEXT_HALO = 'color-mix(in oklab, var(--primary-500, #f59e0b) 30%, transparent)'

let disposers = []

function own(dispose) {
    disposers.push(dispose)
}

function listen(target, type, handler, options) {
    if (!target || !target.addEventListener) {
        return
    }

    target.addEventListener(type, handler, options)
    own(() => target.removeEventListener(type, handler, options))
}

function runDisposers() {
    while (disposers.length) {
        const dispose = disposers.pop()

        try {
            dispose()
        } catch (error) {
            // A failing disposer must not strand the ones queued behind it.
        }
    }
}

/* ------------------------------------------------------------------------- */
/* Remembered progress                                                        */
/* ------------------------------------------------------------------------- */

function readState() {
    try {
        const raw = window.sessionStorage.getItem(STORE)

        return raw ? JSON.parse(raw) : null
    } catch (error) {
        // Storage disabled, a locked-down profile, or corrupt JSON. The journey
        // still draws; only the "what changed since last time" half is lost.
        return null
    }
}

function writeState(state) {
    try {
        window.sessionStorage.setItem(STORE, JSON.stringify(state))
    } catch (error) {
        // Quota or no storage at all. Not worth a single degraded pixel.
    }
}

function clearState() {
    try {
        window.sessionStorage.removeItem(STORE)
    } catch (error) {
        // As above.
    }
}

/* ------------------------------------------------------------------------- */
/* Finding the checklist                                                      */
/* ------------------------------------------------------------------------- */

/**
 * Read the journey out of one widget, or return null if that widget is not the
 * checklist.
 *
 * There is no class to key off -- the view is built entirely from stock
 * `x-filament::section` components -- so the signature is structural: a run of
 * sibling COMPACT sections nested inside another section, each with a header
 * whose heading starts with the ordinal the view prepends ("1. ", "2. ").
 * That prefix is concatenated in Blade rather than translated, so it survives
 * the panel's French locale. Nothing else in this project nests compact
 * sections (checked against every view under resources/views).
 *
 * @param {Element} widget
 * @returns {?{widget: Element, column: Element, steps: Array<object>}}
 */
function readJourney(widget) {
    if (!widget || !widget.querySelectorAll) {
        return null
    }

    const nested = widget.querySelectorAll('.fi-section .fi-section.fi-compact')

    if (nested.length < 2) {
        return null
    }

    const column = nested[0].parentElement

    if (!column) {
        return null
    }

    const steps = []

    for (const child of column.children) {
        // Our own layer lives in this column too, and a re-run can read the
        // column back before the sweep has caught an orphan from a previous one.
        if (child.hasAttribute(OWNED)) {
            continue
        }

        // Anything else in the column that is not a compact section means this
        // is not the shape we are looking for, so bail rather than guess.
        if (!child.classList.contains('fi-section') || !child.classList.contains('fi-compact')) {
            return null
        }

        const header = child.querySelector(':scope > .fi-section-header')
        const heading = header && header.querySelector('.fi-section-header-heading')

        if (!heading || !/^\s*\d+\s*\./.test(heading.textContent || '')) {
            return null
        }

        const icon = header.querySelector(':scope > .fi-icon')

        // `fi-color-success` is what the view swaps the icon to once a step is
        // done. The fallback reads the other half of the same condition: the CTA
        // button is rendered only `@unless ($step['done'])`.
        const cta = header.querySelector(':scope > .fi-section-header-after-ctn')
        const done = icon ? icon.classList.contains('fi-color-success') : !cta

        steps.push({ section: child, header, icon, cta, done })
    }

    return steps.length >= 2 ? { widget, column, steps } : null
}

function findJourney() {
    const widgets = document.querySelectorAll('.fi-wi-widget')

    for (const widget of widgets) {
        const journey = readJourney(widget)

        if (journey) {
            return journey
        }
    }

    return null
}

/* ------------------------------------------------------------------------- */
/* Snapshot                                                                   */
/* ------------------------------------------------------------------------- */

/* Inline properties the motion layer writes and the stylesheet does not. Left in
   a snapshot they would freeze the farewell mid-tween -- a widget replayed at
   40% opacity because it was captured while the entrance was still running. */
const TRANSIENT = [
    'transform',
    'transform-origin',
    'translate',
    'rotate',
    'scale',
    'opacity',
    'filter',
    'clip-path',
    'visibility',
    'will-change',
    'transition',
    'animation',
]

/**
 * Serialise the widget as it would look untouched: our own nodes removed and
 * every property any motion module might currently be holding stripped, while
 * the server's own inline styles (the widget's grid-column span) survive.
 *
 * @param {Element} widget
 * @returns {?string}
 */
function snapshotHtml(widget) {
    if (!widget || !widget.isConnected) {
        return null
    }

    const clone = widget.cloneNode(true)

    clone.querySelectorAll(`[${OWNED}]`).forEach((node) => node.remove())

    const styled = [clone, ...clone.querySelectorAll('[style]')]

    styled.forEach((node) => {
        if (!node.style) {
            return
        }

        TRANSIENT.forEach((property) => node.style.removeProperty(property))

        if (!node.getAttribute('style')) {
            node.removeAttribute('style')
        }
    })

    const html = clone.outerHTML

    return html.length <= SNAPSHOT_LIMIT ? html : null
}

/**
 * Turn a stored snapshot back into a node that is safe to put on the page.
 *
 * Parsed through DOMParser, which builds an inert document -- nothing runs, and
 * nothing is fetched -- and then stripped of everything that would make the
 * clone behave like the original: `wire:`/`x-` attributes (Livewire would try to
 * hydrate a second copy of a component that no longer exists, Alpine would
 * initialise it), duplicate `id`s, live `href`s, and any inline event handler.
 * The result is a picture of a widget, not a widget.
 *
 * @param {string} html
 * @returns {?Element}
 */
function reviveSnapshot(html) {
    let parsed = null

    try {
        parsed = new DOMParser().parseFromString(html, 'text/html')
    } catch (error) {
        return null
    }

    const root = parsed && parsed.body ? parsed.body.firstElementChild : null

    if (!root) {
        return null
    }

    root.querySelectorAll('script, style, link, iframe, object, embed').forEach((node) => node.remove())

    const nodes = [root, ...root.querySelectorAll('*')]

    nodes.forEach((node) => {
        // `href` is dropped from links only. Stripping it everywhere would take
        // an SVG <use xlink:href> with it and gut an icon.
        const isLink = node.tagName === 'A'

        Array.from(node.attributes).forEach((attribute) => {
            const name = attribute.name

            const live =
                name.startsWith('wire:') ||
                name.startsWith('x-') ||
                name.startsWith('on') ||
                name.startsWith('@') ||
                name.startsWith(':') ||
                name === 'id' ||
                name === 'name' ||
                name === 'form' ||
                name === 'tabindex' ||
                (isLink && name === 'href')

            if (live) {
                node.removeAttribute(name)
            }
        })
    })

    return document.importNode(root, true)
}

/* ------------------------------------------------------------------------- */
/* The spine                                                                  */
/* ------------------------------------------------------------------------- */

function spawn(parent, css) {
    const node = document.createElement('div')

    node.setAttribute(OWNED, 'part')
    node.style.cssText = css
    parent.appendChild(node)

    return node
}

/**
 * A circle centred on an already-measured box, expressed in `layer` coordinates.
 * Takes rects rather than elements so callers can do all their reading in one
 * pass and all their writing in another.
 *
 * @param {Element} layer
 * @param {DOMRect} origin  the layer's own box
 * @param {DOMRect} box     what the ring should surround
 * @param {number} pad      px of clearance around it
 * @param {string} shadow
 */
function ringOver(layer, origin, box, pad, shadow) {
    const radius = Math.max(box.width, box.height) / 2 + pad

    return spawn(
        layer,
        [
            'position:absolute',
            `left:${(box.left + box.width / 2 - origin.left - radius).toFixed(1)}px`,
            `top:${(box.top + box.height / 2 - origin.top - radius).toFixed(1)}px`,
            `width:${(radius * 2).toFixed(1)}px`,
            `height:${(radius * 2).toFixed(1)}px`,
            'border-radius:50%',
            `box-shadow:${shadow}`,
            'opacity:0',
        ].join(';'),
    )
}

/**
 * Build the connecting line down the left of a step column.
 *
 * Geometry is measured, never assumed: the spine is centred in whatever gap
 * exists between a card's leading edge and its icon, and it runs from the first
 * icon's centre to the last icon's centre. If that gap or that distance is too
 * small to work with, this returns null and the caller carries on without a
 * line -- an unreadable or misplaced rail is worse than no rail.
 *
 * The fill is `scaleY` from a top origin and the head marker rides on `y`, so
 * progress costs one composited transform per frame instead of a layout pass.
 *
 * @returns {?{layer: Element, place: Function, fractionFor: Function, set: Function, to: Function}}
 */
function buildRail({ gsap, column, steps, withHead }) {
    const rtl = getComputedStyle(column).direction === 'rtl'

    const layer = document.createElement('div')
    layer.setAttribute(OWNED, 'layer')
    layer.setAttribute('aria-hidden', 'true')
    // pointer-events:none is load-bearing: this layer covers the CTA buttons.
    layer.style.cssText = 'position:absolute;inset:0;pointer-events:none;z-index:1'

    const track = spawn(
        layer,
        `position:absolute;width:2px;border-radius:2px;background:${TRACK_TINT};opacity:0`,
    )

    const fill = spawn(
        track,
        [
            'position:absolute',
            'inset:0',
            'border-radius:inherit',
            `background:${DONE_TINT}`,
            'transform-origin:50% 0%',
            'transform:scaleY(0)',
        ].join(';'),
    )

    const head = withHead
        ? spawn(
              track,
              [
                  'position:absolute',
                  'left:50%',
                  'top:0',
                  'width:9px',
                  'height:9px',
                  'margin-left:-4.5px',
                  'margin-top:-4.5px',
                  'border-radius:50%',
                  `background:${NEXT_TINT}`,
                  `box-shadow:0 0 0 3px ${NEXT_HALO}`,
                  'opacity:0',
              ].join(';'),
          )
        : null

    /* The column becomes the positioning context. `position` -- not a transform
       -- on purpose: it anchors our absolutely positioned layer without making
       the column the containing block for any fixed-position descendant of the
       cards inside it. */
    gsap.set(column, { position: 'relative' })
    column.appendChild(layer)

    let current = null
    let progress = 0

    /** One measuring pass. Every read happens here, before any write. */
    function measure() {
        if (!layer.isConnected) {
            return null
        }

        const origin = layer.getBoundingClientRect()
        const cards = steps.map((step) => step.section.getBoundingClientRect())
        const icons = steps.map((step) => (step.icon ? step.icon.getBoundingClientRect() : null))

        const lead = icons[0]

        if (!lead || !lead.width || !cards[0].width) {
            return null
        }

        const gutter = rtl ? cards[0].right - lead.right : lead.left - cards[0].left

        if (gutter < MIN_GUTTER) {
            return null
        }

        const anchors = steps.map((step, index) => {
            const icon = icons[index]

            // A step whose icon failed to render still gets an anchor, so one
            // missing SVG cannot collapse the whole line.
            return icon && icon.height
                ? icon.top + icon.height / 2 - origin.top
                : cards[index].top + 20 - origin.top
        })

        const span = anchors[anchors.length - 1] - anchors[0]

        if (!(span > MIN_SPAN)) {
            return null
        }

        return {
            top: anchors[0],
            span,
            offset: rtl
                ? origin.right - cards[0].right + gutter / 2
                : cards[0].left - origin.left + gutter / 2,
            anchors,
        }
    }

    /** Re-measure and re-apply. Cheap enough to run on resize and re-render. */
    function place() {
        const next = measure()

        if (!next) {
            gsap.set(track, { opacity: 0 })
            current = null

            return null
        }

        current = next

        gsap.set(track, {
            top: next.top,
            height: next.span,
            left: rtl ? 'auto' : next.offset,
            right: rtl ? next.offset : 'auto',
            opacity: 1,
        })

        gsap.set(fill, { scaleY: progress })

        if (head) {
            gsap.set(head, { y: progress * next.span })
        }

        return next
    }

    /** Where step `index` sits along the line, as 0..1. */
    function fractionFor(index) {
        if (!current || !current.span) {
            return 0
        }

        const clamped = Math.min(Math.max(index, 0), current.anchors.length - 1)

        return (current.anchors[clamped] - current.anchors[0]) / current.span
    }

    function set(value) {
        progress = value
        gsap.set(fill, { scaleY: value })

        if (head && current) {
            gsap.set(head, { y: value * current.span })
        }
    }

    /**
     * Move the fill to `value`. The head rides a separate tween with identical
     * timing rather than being driven from an onUpdate, so both stay on the
     * compositor.
     */
    function to(value, duration, ease, delay) {
        progress = value

        const tl = gsap.timeline({ delay: delay || 0 })

        tl.to(fill, { scaleY: value, duration, ease }, 0)

        if (head && current) {
            tl.to(head, { y: value * current.span, duration, ease }, 0)
            tl.to(head, { opacity: 1, duration: Math.min(duration, 0.4), ease: 'none' }, 0)
        }

        return tl
    }

    // A column that cannot be measured -- zero width, a gutter too narrow to
    // hold the line clear of the icons, steps too close together to span --
    // gets no line at all, and the caller carries on without one.
    if (!place()) {
        layer.remove()

        return null
    }

    return { layer, place, fractionFor, set, to }
}

/* ------------------------------------------------------------------------- */
/* The departure                                                              */
/* ------------------------------------------------------------------------- */

/**
 * Replay the checklist one last time, finish the line, and lift it away.
 *
 * Runs only on the page the widget actually lived on, only from a snapshot
 * taken minutes rather than hours ago, and only once -- the stored state is
 * dropped before a single tween is created, so no failure path can replay it.
 *
 * The overlay is absolutely positioned and therefore outside layout: the real
 * dashboard beneath is already in its final position and never moves. It is
 * inert and `pointer-events: none`, so the page underneath stays fully usable
 * for the ~1.3s this takes, and a wall-clock timer removes it whether or not
 * the timeline ever finishes.
 */
function farewell({ gsap, MOTION, tier, stored }) {
    if (!stored || stored.path !== window.location.pathname || !stored.html) {
        return
    }

    if (!stored.at || Date.now() - stored.at > FAREWELL_TTL) {
        clearState()

        return
    }

    clearState()

    // A low-tier device gets the honest version: the widget is simply gone.
    if (tier === 'low') {
        return
    }

    const mount = document.querySelector('.fi-page-content') ?? document.querySelector('.fi-main')

    if (!mount) {
        return
    }

    const node = reviveSnapshot(stored.html)

    if (!node) {
        return
    }

    const shell = document.createElement('div')
    shell.setAttribute(OWNED, 'farewell')
    shell.setAttribute('aria-hidden', 'true')

    // Below Filament's sticky topbar (30) and its modal overlay (40), above the
    // page content it is standing in front of.
    shell.style.cssText = 'position:absolute;top:0;left:0;right:0;z-index:20;pointer-events:none'

    if ('inert' in HTMLElement.prototype) {
        shell.inert = true
    }

    shell.appendChild(node)

    /*
     * The mount only needs to be a positioning context for as long as the
     * overlay is inside it, and it is given one only if it does not already have
     * one -- a page container that suddenly becomes the offsetParent is a page
     * container that can misplace an open Filament dropdown, and there is no
     * reason to hold that risk open past the second and a half this takes.
     */
    const borrowedPosition = getComputedStyle(mount).position === 'static'

    if (borrowedPosition) {
        mount.style.position = 'relative'
    }

    mount.appendChild(shell)

    const remove = () => {
        shell.remove()

        if (borrowedPosition) {
            mount.style.removeProperty('position')
        }
    }

    own(remove)

    // Leaving the page mid-farewell must take the overlay with it, or it flashes
    // over whichever page arrives next.
    listen(document, 'livewire:navigating', remove, { once: true })

    // The one guarantee that matters here: the overlay leaves. Not "if the
    // timeline completes" -- always.
    const failsafe = window.setTimeout(remove, 5000)
    own(() => window.clearTimeout(failsafe))

    const replay = readJourney(node)
    const rail = replay ? buildRail({ gsap, column: replay.column, steps: replay.steps, withHead: false }) : null

    const tl = gsap.timeline({ onComplete: remove })

    if (rail) {
        rail.set(0)
        tl.add(rail.to(1, 0.75, MOTION.ease), 0.1)
    }

    // The remaining calls to action dissolve as the line passes them: there is
    // nothing left to click, and saying so is the point of the whole beat.
    const ctas = replay
        ? replay.steps.map((step) => step.cta).filter((cta) => cta !== null && cta !== undefined)
        : []

    if (ctas.length) {
        tl.to(ctas, { opacity: 0, y: -4, duration: 0.32, ease: MOTION.easeSoft, stagger: 0.05 }, 0.15)
    }

    tl.to(shell, { y: -16, opacity: 0, duration: 0.45, ease: 'power2.in' }, '+=0.18')
}

/* ------------------------------------------------------------------------- */

export function onboardingJourney({ gsap, ScrollTrigger, MOTION }) {
    if (typeof document === 'undefined' || !document.body) {
        return null
    }

    // Undo the previous run before building anything, and collect any orphan a
    // previous run left behind.
    runDisposers()
    document.querySelectorAll(`[${OWNED}]`).forEach((node) => node.remove())

    // gsap.context() reverts tweens, not listeners, timers or appended nodes.
    // This nested context exists only so the runtime's revert -- on navigation
    // and on a reduced-motion toggle -- reaches the disposers below.
    gsap.context(() => () => runDisposers())

    const tier = window.__vfMotion?.tier ?? 'medium'
    const stored = readState()
    const journey = findJourney()

    // Every page in the panel except a dashboard with an unfinished checklist.
    if (!journey) {
        farewell({ gsap, MOTION, tier, stored })

        return null
    }

    const steps = journey.steps
    const signature = steps.map((step) => (step.done ? '1' : '0')).join('')

    /* --- What changed since last time ------------------------------------ */

    const previous =
        stored &&
        stored.path === window.location.pathname &&
        typeof stored.steps === 'string' &&
        stored.steps.length === steps.length
            ? stored.steps
            : null

    const newlyDone = []

    if (previous) {
        for (let i = 0; i < steps.length; i += 1) {
            if (previous[i] === '0' && steps[i].done) {
                newlyDone.push(i)
            }
        }
    }

    // The step the operator should act on next. `canView()` keeps the widget on
    // screen only while an essential is outstanding, so there is always one.
    const frontierOf = (flags) => {
        const index = flags.indexOf('0')

        return index === -1 ? flags.length - 1 : index
    }

    const frontier = frontierOf(signature)
    const priorFrontier = previous ? frontierOf(previous) : frontier

    /* --- Remember this visit --------------------------------------------- */

    /* Captured now, not read inside `record`. Livewire updates the address bar
       as part of the same navigation that fires `livewire:navigating`, so
       reading it there could file this page's snapshot under the destination's
       path -- and the farewell would then replay on the wrong page. */
    const path = window.location.pathname

    const record = () =>
        writeState({
            path,
            steps: signature,
            at: Date.now(),
            html: snapshotHtml(journey.widget),
        })

    record()

    /*
     * On the way out, in this order: re-take the snapshot so it reflects the
     * page as it was last actually seen, THEN tear down, so our layer does not
     * sit painted over the next page while it boots. Both are registered as
     * `livewire:navigating` listeners, and listeners fire in registration
     * order -- the teardown one removes the other, so it has to be second.
     */
    listen(document, 'livewire:navigating', record, { once: true })
    listen(document, 'livewire:navigating', () => runDisposers(), { once: true })

    /* --- The line -------------------------------------------------------- */

    const rail = buildRail({ gsap, column: journey.column, steps, withHead: true })

    if (rail) {
        own(() => rail.layer.remove())

        const target = rail.fractionFor(frontier)
        const from = newlyDone.length ? rail.fractionFor(priorFrontier) : 0

        rail.set(from)

        // The draw fires from a ScrollTrigger callback, which runs outside the
        // context this module was built in -- so the timeline it creates is not
        // tracked by the runtime's revert and has to be killed by hand.
        let drawing = null

        own(() => {
            if (drawing) {
                drawing.kill()
                drawing = null
            }
        })

        const draw = () => {
            if (!rail.layer.isConnected) {
                return
            }

            // The advance waits for the check that caused it to land first --
            // cause, then effect.
            drawing = rail.to(target, tier === 'low' ? 0.5 : 0.85, MOTION.ease, newlyDone.length ? 0.34 : 0.3)
        }

        // The checklist sits at the top of the dashboard and is all but always
        // in view, but a narrow window can push it under the fold. Same gate the
        // counters use, for the same reason: an empty gauge is a wrong-looking
        // reading, so it is only ever adopted just before it is seen.
        if (ScrollTrigger) {
            ScrollTrigger.create({ trigger: journey.widget, start: 'top 97%', once: true, onEnter: draw })
        } else {
            draw()
        }

        // Insurance. If that trigger never fires -- a mis-measured refresh, a
        // late Livewire render shifting the page -- the line still ends up
        // telling the truth, it just does not animate there.
        const settle = window.setTimeout(() => {
            if (rail.layer.isConnected) {
                rail.set(target)
            }
        }, 4000)

        own(() => window.clearTimeout(settle))

        /* Geometry follows the page. The description text wraps differently at
           every width, so a resize moves every anchor. */
        let frame = 0

        const resync = () => {
            if (frame) {
                return
            }

            frame = requestAnimationFrame(() => {
                frame = 0

                if (!rail.layer.isConnected) {
                    return
                }

                rail.place()
            })
        }

        own(() => {
            if (frame) {
                cancelAnimationFrame(frame)
            }
        })

        listen(window, 'resize', resync, { passive: true })
        listen(document, 'livewire:update', resync)

        if (typeof ResizeObserver === 'function') {
            const observer = new ResizeObserver(resync)
            observer.observe(journey.column)
            own(() => observer.disconnect())
        }
    }

    /* --- A step completing ------------------------------------------------ */

    const icons = steps.map((step) => step.icon).filter((icon) => icon !== null && icon !== undefined)

    if (newlyDone.length) {
        /* Read pass. Everything this sequence needs to know about the page is
           measured here, before the first node is created -- otherwise each
           spawn() would invalidate layout for the next getBoundingClientRect()
           and the browser would do one full pass per step. */
        const decorate = rail && tier !== 'low'
        const origin = decorate ? rail.layer.getBoundingClientRect() : null

        const plan = newlyDone.map((index, order) => {
            const step = steps[index]

            return {
                step,
                at: order * 0.22,
                icon: decorate && step.icon ? step.icon.getBoundingClientRect() : null,
                card: decorate && tier === 'high' ? step.section.getBoundingClientRect() : null,
            }
        })

        // Write pass.
        const tl = gsap.timeline()

        plan.forEach(({ step, at, icon, card }) => {
            if (step.icon) {
                /* The check arrives rather than being already there: it drops in
                   under the panel's spring, overshoots by a hair and settles.
                   clearProps hands the icon straight back to the stylesheet, so
                   nothing is left holding a transform on it. */
                tl.fromTo(
                    step.icon,
                    { scale: 0.25, rotation: -32, opacity: 0, transformOrigin: '50% 50%' },
                    {
                        scale: 1,
                        rotation: 0,
                        opacity: 1,
                        duration: 0.78,
                        ease: MOTION.spring,
                        clearProps: 'transform,transformOrigin,opacity,willChange',
                    },
                    at,
                )
            }

            // A ring leaving the icon. Drawn in our own layer rather than
            // inserted into the step, which is markup Livewire owns and morphs.
            if (icon && icon.width) {
                const ping = ringOver(rail.layer, origin, icon, 5, `0 0 0 2px ${DONE_HALO}`)

                tl.fromTo(
                    ping,
                    { scale: 0.5, opacity: 0.9 },
                    { scale: 2.1, opacity: 0, duration: 0.9, ease: 'power2.out', onComplete: () => ping.remove() },
                    at + 0.08,
                )
            }

            // The card itself acknowledges once, with an outline drawn over it
            // that never touches its box.
            if (card && card.width) {
                const flash = spawn(
                    rail.layer,
                    [
                        'position:absolute',
                        `left:${(card.left - origin.left).toFixed(1)}px`,
                        `top:${(card.top - origin.top).toFixed(1)}px`,
                        `width:${card.width.toFixed(1)}px`,
                        `height:${card.height.toFixed(1)}px`,
                        // Matches `rounded-lg` on a compact section.
                        'border-radius:8px',
                        `box-shadow:0 0 0 1.5px ${DONE_TINT}`,
                        'opacity:0',
                    ].join(';'),
                )

                tl.to(flash, { opacity: 0.85, duration: 0.22, ease: MOTION.easeSoft }, at + 0.05)
                tl.to(
                    flash,
                    { opacity: 0, duration: 0.6, ease: 'power2.out', onComplete: () => flash.remove() },
                    at + 0.34,
                )
            }
        })
    }

    /* Rule one, in one line: whatever happened above, no icon is left invisible
       or rotated. Runs on the wall clock, not on a tween completing. */
    if (icons.length) {
        const heal = window.setTimeout(() => {
            icons.forEach((icon) => {
                if (!icon.isConnected) {
                    return
                }

                if (Number(gsap.getProperty(icon, 'opacity')) < 0.99) {
                    gsap.set(icon, { clearProps: 'transform,transformOrigin,opacity,willChange' })
                }
            })
        }, 3000)

        own(() => window.clearTimeout(heal))
    }

    /* --- Where to go next -------------------------------------------------
       The one continuous effect in this module, so it plays by the continuous
       rules: nothing at all on a low-tier device, no ring on a medium one, and
       stopped outright whenever the tab is hidden or the widget is off screen.
       Amplitudes are deliberately tiny -- this is a hint, not a nag. */

    const next = steps[frontier]

    if (tier !== 'low' && next && next.icon && !next.done) {
        const icon = next.icon
        const ambient = gsap.timeline({ repeat: -1, repeatDelay: 1.6, paused: true })

        ambient.to(
            icon,
            {
                scale: 1.085,
                duration: 0.9,
                ease: 'sine.inOut',
                yoyo: true,
                repeat: 1,
                transformOrigin: '50% 50%',
            },
            0,
        )

        if (tier === 'high' && rail) {
            const origin = rail.layer.getBoundingClientRect()
            const box = icon.getBoundingClientRect()

            if (box.width) {
                const ping = ringOver(rail.layer, origin, box, 5, `0 0 0 2px ${NEXT_HALO}`)

                ambient.fromTo(
                    ping,
                    { scale: 0.55, opacity: 0.7 },
                    { scale: 2, opacity: 0, duration: 1.25, ease: 'power2.out' },
                    0,
                )
            }
        }

        let tabVisible = !document.hidden
        let onScreen = true
        let armed = false

        const sync = () => {
            if (armed && tabVisible && onScreen) {
                ambient.play()
            } else {
                // pause(0) rather than pause(): stopping mid-breath would park a
                // permanently enlarged icon on a page nobody is looking at.
                ambient.pause(0)
            }
        }

        listen(document, 'visibilitychange', () => {
            tabVisible = !document.hidden
            sync()
        })

        if (ScrollTrigger) {
            ScrollTrigger.create({
                trigger: journey.widget,
                start: 'top bottom',
                end: 'bottom top',
                // onRefresh as well as onToggle: onToggle only fires on a
                // CHANGE, so a widget that starts off screen would otherwise
                // never report it.
                onRefresh: (self) => {
                    onScreen = self.isActive
                    sync()
                },
                onToggle: (self) => {
                    onScreen = self.isActive
                    sync()
                },
            })
        }

        // A beat after the entrance has landed and the line has drawn, so the
        // hint is the last thing that happens rather than one more thing
        // arriving at once. `armed` is what holds it back -- the ScrollTrigger
        // above reports its initial state the moment it is created, which would
        // otherwise start the breathing on the first frame of the page.
        const start = window.setTimeout(() => {
            armed = true
            sync()
        }, 1500)

        own(() => {
            window.clearTimeout(start)
            ambient.kill()

            // Plain DOM rather than a tween: this runs during teardown, and a
            // tween created then would outlive the context that should own it.
            if (icon.isConnected) {
                icon.style.removeProperty('transform')
                icon.style.removeProperty('transform-origin')
                icon.style.removeProperty('will-change')
            }
        })
    }

    return null
}
