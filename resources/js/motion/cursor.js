/*
 * Pointer field
 * -------------
 * The layer that follows the operator's pointer. Three things, at three
 * different scales and three different speeds, which is what makes it read as a
 * field rather than as a gimmick:
 *
 *   1. A wide, slow glow that trails across the PAGE BACKGROUND. It sits at
 *      `z-index: -1` as a direct child of <body>, so it paints above the canvas
 *      background and below every piece of in-flow content on the page. It is
 *      structurally incapable of touching text contrast: nothing it draws is
 *      ever in front of anything.
 *   2. A state-aware ring that tracks the pointer closely, grows over anything
 *      interactive, takes the danger tint over anything destructive, collapses
 *      into an I-beam over text entry, and compresses on press.
 *   3. A radial highlight that follows the pointer inside a `.fi-section`,
 *      drawn as a `background-image` on the card -- behind its own text, on top
 *      of its own background colour, at single-digit alpha.
 *
 * Constraints this file is built around:
 *
 *   - THE NATIVE CURSOR IS NEVER HIDDEN. `cursor: none` is not set anywhere.
 *     People click "Revoke key" and "Delete server" in this panel; taking away
 *     the OS cursor and replacing it with a ring that lags by 200ms is a way to
 *     miss a target. The ring is an accent around the real cursor, not a
 *     replacement for it.
 *   - NOTHING INTERCEPTS INPUT. Every node created here is `pointer-events:
 *     none` and lives outside the layout, so nothing can shadow a control.
 *   - POINTER: FINE ONLY. Built inside a `gsap.matchMedia()` branch, so on a
 *     touch device it does not exist -- and a hybrid device that reports a fine
 *     pointer still suppresses the whole thing the moment a `touch` pointer
 *     event arrives, because a ring parked where a finger last tapped is just
 *     litter.
 *   - NOTHING ON THIS PAGE DEPENDS ON IT. This module hides no content and
 *     lowers no opacity on anything the server rendered. Its own nodes start
 *     invisible and are only revealed by a real pointer movement, so the
 *     failure mode of every path in here is "the panel looks exactly as it
 *     would without this file" -- which is why there is no visibility failsafe
 *     to write.
 *   - NO PERSISTENT TRANSFORM ON PANEL MARKUP. The only elements that ever hold
 *     a transform are the three nodes this file appends to <body>. Filament
 *     renders action modals inline inside sections, and a transform on a card
 *     would anchor those modals to the card; the card highlight is therefore a
 *     background paint and nothing else.
 *
 * Cleanup: `gsap.context()` reverts tweens, not listeners and not appended DOM.
 * Each run removes the previous run's nodes by attribute before building, and a
 * nested context exists purely so the runtime's revert() reaches the disposer
 * that unbinds every listener and deletes every node and attribute.
 */

import { CustomEase } from 'gsap/CustomEase'

/** Marks the nodes this module appends to <body>, so a run can delete the
 *  previous run's leftovers even if that run was interrupted mid-teardown. */
const NODE_ATTR = 'data-vf-cursor'

/** On a card the pointer has visited at least once. Left in place afterwards so
 *  the gradient has somewhere to fade back to. */
const GLOW_ATTR = 'data-vf-glow'

/** On the one card the pointer is inside right now. */
const GLOW_ON_ATTR = 'data-vf-glow-on'

const STYLE_ID = 'vf-cursor'

/*
 * Cards that have a surface of their own to light. Mirrors the `:not()` chain
 * motion.css uses for section elevation: an uncontained section is not a card,
 * and an aside section puts its surface on an inner container instead, so
 * painting a gradient on the outer element would paint it on nothing.
 */
const CARD = '.fi-section:not(.fi-section-not-contained):not(.fi-aside)'

/*
 * Anything the ring should acknowledge. Filament's own component classes first
 * -- every one of them read out of vendor/filament/*\/resources rather than
 * guessed, because an invented class name here compiles to a selector that
 * silently never matches -- then the plain HTML fallbacks, which is what covers
 * custom Blade in this project's own views.
 *
 * Table rows are deliberately absent even though a `.fi-ta-row.fi-clickable` is
 * clickable. interactions.js already answers a hovered row with a rail and a
 * light, and dragging a 44px ring across dense tabular data while an operator
 * reads an IP address out of it is precisely the sort of thing this panel
 * should not do.
 */
const INTERACTIVE = [
    '.fi-btn',
    '.fi-icon-btn',
    '.fi-link',
    '.fi-sidebar-item-btn',
    '.fi-tabs-item',
    '.fi-dropdown-list-item',
    '.fi-pagination-item-btn',
    '.fi-ta-header-cell-sort-btn',
    'a[href]',
    'button',
    'summary',
    'select',
    'label[for]',
    'input[type="checkbox"]',
    'input[type="radio"]',
    'input[type="file"]',
    'input[type="range"]',
    'input[type="submit"]',
    'input[type="button"]',
    '[role="button"]',
    '[role="tab"]',
    '[role="menuitem"]',
].join(', ')

/*
 * Text entry, listed by input TYPE rather than by Filament's `fi-input` class.
 * The class is on the <input> itself (vendor/filament/support/resources/css/
 * components/input/index.css) but says nothing about what the control does --
 * Filament puts it on selects and on its date pickers too -- and this project
 * also renders plain inputs in its own Blade. The wrapper `.fi-input-wrp` is
 * left out on purpose: it wraps selects as well, and it has no padding of its
 * own that the field does not already cover.
 */
const TEXT_ENTRY = [
    'textarea',
    'input:not([type])',
    'input[type="text"]',
    'input[type="search"]',
    'input[type="email"]',
    'input[type="password"]',
    'input[type="number"]',
    'input[type="url"]',
    'input[type="tel"]',
    '[contenteditable=""]',
    '[contenteditable="true"]',
].join(', ')

/*
 * Actions that destroy something, so the ring can wear the danger tint over
 * them. Filament gives most of these `fi-color-danger`, which is checked first;
 * this is the second line of defence for a destructive action a project has
 * dressed in some other colour. French included -- this panel's UI is French.
 * Deliberately a shorter list than the one in interactions.js: there, a wrong
 * answer means a Delete button leans toward your cursor; here it means a ring is
 * the wrong colour for a moment.
 */
const DESTRUCTIVE =
    /delete|destroy|revoke|terminate|purge|supprim|r[ée]voqu|d[ée]sactiv|r[ée]sili|d[ée]tach/i

/*
 * Ring geometry per state, as multipliers of the resting 22px circle. Scale
 * rather than width/height on purpose: the ring is repositioned every frame and
 * animating a layout property on it would put a layout pass in the pointer's
 * path. The cost is that the 1px border scales with it -- which is why the top
 * of the range is 2, not 4.
 *
 * `text` is the same ring squeezed into a capsule: 3px wide, 50px tall, which
 * reads as an I-beam sitting alongside the OS one.
 */
const STATES = {
    idle: { x: 1, y: 1, alpha: 0.45 },
    hot: { x: 2, y: 2, alpha: 0.9 },
    danger: { x: 2, y: 2, alpha: 0.95 },
    text: { x: 0.14, y: 2.3, alpha: 0.8 },
}

/** How much a press collapses the ring, and how bright it goes while held. */
const PRESS_SQUEEZE = 0.72
const PRESS_ALPHA = 1.1

/** Resting opacity of the background glow. Low: it is a wash on the page
 *  background, not a light source. */
const FIELD_ALPHA = 0.85

/** Stop walking up the tree looking for a state. Deep Filament forms nest well
 *  past this, and anything further up is not what the pointer is "on". */
const WALK_LIMIT = 14

const CSS = `
/* Registered so they are typed: an unregistered custom property cannot be
   transitioned, and the lit ramp below is a CSS transition. */
@property --vf-cursor-lit { syntax: '<number>'; inherits: false; initial-value: 0; }
@property --vf-cursor-cx { syntax: '<percentage>'; inherits: false; initial-value: 50%; }
@property --vf-cursor-cy { syntax: '<percentage>'; inherits: false; initial-value: 50%; }

/* Every colour here is Filament's own runtime palette, so the field cannot
   drift away from the panel it sits behind. The literals are only reached if
   that palette is missing entirely. */
:root {
    --vf-cursor-ring: color-mix(in oklab, var(--primary-500, #6366f1) 60%, transparent);
    --vf-cursor-ring-danger: color-mix(in oklab, var(--danger-500, #ef4444) 66%, transparent);
    --vf-cursor-fill: color-mix(in oklab, var(--primary-500, #6366f1) 16%, transparent);
    --vf-cursor-field: color-mix(in oklab, var(--primary-500, #6366f1) 15%, transparent);
    --vf-cursor-card: color-mix(in oklab, var(--primary-500, #6366f1) 7%, transparent);
}

.dark {
    --vf-cursor-ring: color-mix(in oklab, var(--primary-400, #818cf8) 66%, transparent);
    --vf-cursor-ring-danger: color-mix(in oklab, var(--danger-400, #f87171) 70%, transparent);
    --vf-cursor-fill: color-mix(in oklab, var(--primary-400, #818cf8) 20%, transparent);
    --vf-cursor-field: color-mix(in oklab, var(--primary-400, #818cf8) 12%, transparent);
    --vf-cursor-card: color-mix(in oklab, var(--primary-400, #818cf8) 6%, transparent);
}

/* --- The three appended nodes ----------------------------------------------
   All three are positioned from the top-left corner of the viewport and moved
   with GSAP transforms only, so none of them ever costs a layout. \`left\` and
   \`top\` rather than the logical properties because they are driven from
   clientX/clientY, which are measured from the physical left edge in RTL too.

   All three rest at \`opacity: 0\` and are faded up by the first real pointer
   movement. No \`visibility\` anywhere: GSAP owns \`opacity\` outright here, and a
   \`visibility: hidden\` in the stylesheet would outrank an inline opacity and
   leave the whole layer permanently invisible. */

.vf-cursor-field {
    position: fixed;
    top: 0;
    left: 0;
    width: 30rem;
    height: 30rem;
    /* Behind every piece of in-flow content on the page. Filament puts the app
       background on \`.fi-body\`, and with no background on <html> that colour is
       propagated to the canvas -- so this paints on top of the app background
       and underneath the sidebar, the topbar, and every card. That assumption is
       verified before the node is created; see canPaintBehindContent(). */
    z-index: -1;
    pointer-events: none;
    border-radius: 50%;
    background-image: radial-gradient(closest-side, var(--vf-cursor-field), transparent 74%);
    opacity: 0;
    will-change: transform, opacity;
}

.vf-cursor-ring {
    position: fixed;
    top: 0;
    left: 0;
    width: 22px;
    height: 22px;
    border: 1px solid var(--vf-cursor-ring);
    border-radius: 999px;
    /* Above Filament's deepest layer (modals and notifications are z-50), because
       a cursor that disappears when a dialog opens is a cursor that is wrong
       exactly when precision matters. Hollow, so it covers nothing. */
    z-index: 9999;
    pointer-events: none;
    opacity: 0;
    will-change: transform, opacity;
    /* Colour is CSS's, geometry is GSAP's. The two never touch the same
       property, so neither can smear the other. */
    transition:
        border-color 200ms var(--vf-ease, ease),
        background-color 200ms var(--vf-ease, ease);
}

.vf-cursor-ring[data-vf-cursor-state='danger'] {
    border-color: var(--vf-cursor-ring-danger);
}

.vf-cursor-ring[data-vf-cursor-state='text'] {
    background-color: var(--vf-cursor-fill);
}

.vf-cursor-ripple {
    position: fixed;
    top: 0;
    left: 0;
    width: 34px;
    height: 34px;
    border: 1px solid var(--vf-cursor-ring);
    border-radius: 999px;
    z-index: 9998;
    pointer-events: none;
    opacity: 0;
    will-change: transform, opacity;
}

/* --- Card highlight --------------------------------------------------------
   A \`background-image\` on the card itself, which paints above its background
   colour and below every one of its children -- so no text on the card is ever
   in front of it, and no element has to become a positioning context for a
   pseudo-element. The alpha is ramped through a registered custom property
   rather than by swapping the gradient, so the fade costs one interpolated
   number instead of re-resolving an image. */

${CARD}[${GLOW_ATTR}] {
    background-image: radial-gradient(
        20rem circle at var(--vf-cursor-cx) var(--vf-cursor-cy),
        color-mix(in oklab, var(--vf-cursor-card) calc(var(--vf-cursor-lit) * 100%), transparent),
        transparent 62%
    );
    /* motion.css transitions box-shadow on this exact selector for the card's
       hover elevation. This rule is more specific and unlayered, so touching
       \`transition\` at all would replace that whole list and the elevation would
       start snapping. It is restated here rather than dropped.

       Longhands rather than the shorthand: a custom property is a legal
       transition-property, but spelling it out leaves no room for an engine to
       disagree about where the dashed ident ends. */
    transition-property: --vf-cursor-lit, box-shadow;
    transition-duration: 260ms, var(--vf-enter, 420ms);
    transition-timing-function: var(--vf-ease, ease), var(--vf-ease-soft, ease);
}

${CARD}[${GLOW_ON_ATTR}] {
    --vf-cursor-lit: 1;
}
`

/**
 * The ring's state changes.
 *
 * A `back.out()` cannot express this: the ring has to be at very nearly its new
 * size within the first fifth of the tween, because its size is what tells you
 * what is under the pointer and that information is worthless late -- but it
 * still has to settle rather than stop dead. So: an almost vertical rise, a
 * small overshoot, and a slow return. Held as an object rather than referenced
 * by name so a name collision with another module cannot silently change it.
 */
let settleEase = null

/**
 * The pointer field.
 *
 * @param {{gsap: import('gsap').gsap, ScrollTrigger: object, MOTION: object}} api
 * @returns {null}
 */
export function cursorField({ gsap, MOTION }) {
    if (typeof document === 'undefined' || !document.body) {
        return null
    }

    gsap.registerPlugin(CustomEase)

    // Named so it is inspectable in devtools; the returned object is what is
    // actually used.
    settleEase = CustomEase.create('vfCursorSettle', 'M0,0 C0.2,0 0.28,1.06 0.52,1.06 0.72,1.06 0.78,1 1,1')

    // Undo the previous run first, including one that never reached its own
    // teardown. Nodes go by attribute; the card attributes go too, because the
    // stylesheet that gives them meaning is about to be replaced.
    document.querySelectorAll('[' + NODE_ATTR + ']').forEach((node) => node.remove())
    forgetCards()

    const clamp = gsap.utils.clamp

    /*
     * `gsap.context()` with no argument hands back the context we are currently
     * inside -- the runtime's. Tweens created later, from a pointer handler,
     * would otherwise belong to no context at all: nothing would revert them,
     * and the inline transforms they left would survive the next navigation.
     * Routing them through `add()` puts them back under the runtime's control.
     */
    const host = gsap.context()

    /**
     * Run `fn` as part of the runtime's context and hand back its result. The
     * result is fished out through a closure because `context.add()` treats a
     * returned *function* as a cleanup callback and would call it on revert --
     * and `gsap.quickTo()` returns a function.
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

    // gsap.context() reverts tweens, not listeners. This nested context exists
    // only so the runtime's revert() reaches the disposer below.
    gsap.context(() => () => scope.dispose())

    injectStylesheet(scope)

    const mm = gsap.matchMedia()
    scope.add(() => mm.revert())

    /*
     * Both conditions are reported rather than composed into one query, so the
     * branch can tell "no fine pointer" (build nothing at all) apart from "fine
     * pointer, narrow window" (build the ring, skip the 30rem background glow,
     * which is the only part of this that costs real fill rate). matchMedia
     * reverts and re-runs the branch when either flips, so resizing a window
     * across the boundary cleans up after itself.
     */
    mm.add(
        {
            fine: '(pointer: fine)',
            wide: '(min-width: 64rem)',
        },
        (context) => {
            const conditions = context.conditions || {}

            // The hard requirement. No fine pointer, no field -- not a hidden
            // one, not a disabled one, none at all.
            if (!conditions.fine) {
                return undefined
            }

            const branch = createScope()

            build({
                gsap,
                MOTION,
                clamp,
                owned,
                scope: branch,
                withField: Boolean(conditions.wide) && canPaintBehindContent(),
            })

            return () => branch.dispose()
        },
    )

    return null
}

/* ------------------------------------------------------------------------- */
/* Build                                                                      */
/* ------------------------------------------------------------------------- */

function build({ gsap, MOTION, clamp, owned, scope, withField }) {
    const ring = makeNode('vf-cursor-ring')
    const ripple = makeNode('vf-cursor-ripple')
    const field = withField ? makeNode('vf-cursor-field') : null

    const nodes = field ? [ring, ripple, field] : [ring, ripple]

    nodes.forEach((node) => {
        document.body.appendChild(node)
        scope.add(() => node.remove())
    })

    // Centred on their own coordinates once, so every later write is a plain
    // x/y and the percentage offset never has to be recomputed.
    gsap.set(nodes, { xPercent: -50, yPercent: -50 })

    /*
     * Everything below is a quickTo or a quickSetter, so this module allocates
     * NOTHING once it is built. Seven tweens exist for the life of the page and
     * only ever have their end value rewritten; a `gsap.to()` per pointer event
     * would be thousands of tweens across a shift, every one of them retained by
     * the runtime's context until the next navigation reverts it.
     *
     * The follow durations are the reason the layers read as separate planes:
     * the ring is a fifth of a second behind the pointer, the glow well over a
     * second, so they never look like one cursor with a halo bolted on.
     */
    const ringX = owned(() => gsap.quickTo(ring, 'x', { duration: 0.2, ease: 'power3' }))
    const ringY = owned(() => gsap.quickTo(ring, 'y', { duration: 0.2, ease: 'power3' }))
    const fieldX = field ? owned(() => gsap.quickTo(field, 'x', { duration: 1.15, ease: 'power2' })) : null
    const fieldY = field ? owned(() => gsap.quickTo(field, 'y', { duration: 1.15, ease: 'power2' })) : null

    // One ease for every size change. The overshoot does the work: the ring is
    // at 97% of its new size within about a tenth of a second -- which is what
    // makes the size read as information rather than as an animation -- and then
    // settles over the rest of the tween.
    const sizeVars = { duration: 0.4, ease: settleEase || MOTION.spring }
    const ringScaleX = owned(() => gsap.quickTo(ring, 'scaleX', sizeVars))
    const ringScaleY = owned(() => gsap.quickTo(ring, 'scaleY', sizeVars))
    const ringAlpha = owned(() => gsap.quickTo(ring, 'opacity', { duration: 0.3, ease: MOTION.easeSoft }))
    const fieldAlpha = field
        ? owned(() => gsap.quickTo(field, 'opacity', { duration: MOTION.base, ease: MOTION.easeSoft }))
        : null

    // The ripple has to replay from the start on every press, which a quickTo
    // cannot express -- so it is one paused timeline that gets restarted, and its
    // position is written through setters that create no tween at all.
    const rippleX = gsap.quickSetter(ripple, 'x', 'px')
    const rippleY = gsap.quickSetter(ripple, 'y', 'px')
    const rippleTl = owned(() =>
        gsap
            .timeline({ paused: true })
            .fromTo(
                ripple,
                { scale: 0.4, opacity: 0.8 },
                {
                    scale: 2.2,
                    opacity: 0,
                    duration: MOTION.slow * 0.55,
                    ease: MOTION.ease,
                    // Without this the from-values render the instant the
                    // timeline is built -- a paused timeline still renders its
                    // children once -- and the panel would load with a ring
                    // sitting in the top-left corner of the viewport.
                    immediateRender: false,
                },
            ),
    )

    /* --- state ----------------------------------------------------------- */

    let visible = false
    let placed = false
    let pressed = false
    let state = 'idle'

    /* The card the pointer is inside, and its box. The box is cached because
       reading it on every move would be a forced layout per frame; it is thrown
       away whenever anything could have moved it. */
    let card = null
    let cardRect = null

    let painted = ''

    const applyRing = () => {
        const spec = STATES[state] || STATES.idle
        const squeeze = pressed ? PRESS_SQUEEZE : 1

        // Only when it actually changed: the attribute drives a CSS transition
        // on border-color, and re-writing the same value on every press and
        // release would restart that transition for no reason.
        if (painted !== state) {
            painted = state
            ring.setAttribute('data-vf-cursor-state', state)
        }

        ringScaleX(spec.x * squeeze)
        ringScaleY(spec.y * squeeze)
        ringAlpha(visible ? Math.min(1, spec.alpha * (pressed ? PRESS_ALPHA : 1)) : 0)

        if (fieldAlpha) {
            fieldAlpha(visible ? FIELD_ALPHA : 0)
        }
    }

    const show = (x, y) => {
        if (!placed) {
            placed = true

            /*
             * Snap on first sighting so nothing sweeps across the page from the
             * top-left corner -- but only from a ring that is genuinely
             * invisible. Come back through the window fast enough and the fade
             * out is still running, and teleporting a half-lit ring across the
             * viewport is worse than letting it catch up.
             *
             * The two-argument form of quickTo, not gsap.set(): a quickTo tween
             * tracks its own idea of the current value, so a set() behind its
             * back would be undone by the very next call. Passing start AND end
             * moves the tween itself.
             */
            if (Number(gsap.getProperty(ring, 'opacity')) < 0.05) {
                ringX(x, x)
                ringY(y, y)

                if (fieldX) {
                    fieldX(x, x)
                    fieldY(y, y)
                }
            }
        }

        if (visible) {
            return
        }

        visible = true
        applyRing()
    }

    const hide = () => {
        if (!visible && !card) {
            return
        }

        visible = false
        placed = false
        pressed = false
        unlightCards(null)
        card = null
        cardRect = null
        applyRing()
    }

    /** Point the card's gradient at the pointer. Measures only when the cached
     *  box has been thrown away, and never after a write in the same task. */
    const paintCard = (x, y) => {
        if (!card) {
            return
        }

        if (!cardRect) {
            cardRect = card.getBoundingClientRect()
        }

        if (!cardRect.width || !cardRect.height) {
            return
        }

        card.style.setProperty(
            '--vf-cursor-cx',
            clamp(0, 100, ((x - cardRect.left) / cardRect.width) * 100).toFixed(2) + '%',
        )
        card.style.setProperty(
            '--vf-cursor-cy',
            clamp(0, 100, ((y - cardRect.top) / cardRect.height) * 100).toFixed(2) + '%',
        )
    }

    /* --- movement -------------------------------------------------------- */

    const move = (event) => {
        // A hybrid laptop reports `pointer: fine` and still has a touchscreen. A
        // finger has no hover state, so anything it lit would stay lit.
        if (event.pointerType === 'touch') {
            hide()

            return
        }

        const x = event.clientX
        const y = event.clientY

        show(x, y)

        ringX(x)
        ringY(y)

        if (fieldX) {
            fieldX(x)
            fieldY(y)
        }

        paintCard(x, y)
    }

    /* --- target tracking -------------------------------------------------- */

    const over = (event) => {
        if (event.pointerType === 'touch') {
            hide()

            return
        }

        const target = event.target

        if (!target || target.nodeType !== 1) {
            return
        }

        const next = stateFor(target)

        if (next !== state) {
            state = next
            applyRing()
        }

        const nextCard = target.closest ? target.closest(CARD) : null

        if (nextCard !== card) {
            unlightCards(nextCard)

            card = nextCard
            cardRect = null

            if (card) {
                // The paint rule and the lit flag are separate attributes so the
                // gradient still exists, at zero alpha, on the way out -- with a
                // single attribute the highlight would vanish instead of fading.
                card.setAttribute(GLOW_ATTR, '')
                card.setAttribute(GLOW_ON_ATTR, '')

                // Aimed before it is lit. A card that faded up centred and then
                // slid to the pointer would read as two separate events.
                paintCard(event.clientX, event.clientY)
            }
        }
    }

    /* --- press ------------------------------------------------------------ */

    const down = (event) => {
        // Secondary buttons open a context menu; that is not a press.
        if (event.pointerType === 'touch' || event.button > 0) {
            return
        }

        pressed = true
        applyRing()

        // The ripple only answers presses that do something. Firing it on every
        // click -- including a drag across a paragraph of log output -- would
        // turn a flourish into noise.
        if (state === 'hot' || state === 'danger') {
            rippleX(event.clientX)
            rippleY(event.clientY)
            rippleTl.restart()
        }
    }

    const up = () => {
        if (!pressed) {
            return
        }

        pressed = false
        applyRing()
    }

    /* --- wiring ----------------------------------------------------------- */

    scope.on(document, 'pointermove', move, { passive: true })
    scope.on(document, 'pointerover', over, { passive: true })
    scope.on(document, 'pointerdown', down, { passive: true })
    scope.on(document, 'pointerup', up, { passive: true })
    scope.on(document, 'pointercancel', up, { passive: true })

    // Leaving the document never produces a pointerover for anything else, so
    // without this the ring would sit frozen at the edge of the window.
    scope.on(
        document,
        'pointerout',
        (event) => {
            if (!event.relatedTarget) {
                hide()
            }
        },
        { passive: true },
    )

    scope.on(window, 'blur', hide)
    scope.on(document, 'visibilitychange', () => {
        if (document.hidden) {
            hide()
        }
    })

    // The cached box is only valid until something moves under the pointer.
    // Scroll does not bubble, but it does reach a capturing listener on window,
    // which is how nested scrollers -- tables, the sidebar -- are covered too.
    const invalidate = () => {
        cardRect = null
    }

    scope.on(window, 'scroll', invalidate, { passive: true, capture: true })
    scope.on(window, 'resize', invalidate, { passive: true })

    // Livewire morphs a table or a widget in place every 30-60s. If the card the
    // pointer was inside is replaced, the highlight attribute goes with it --
    // but a stale flag on a detached-then-reattached node would leave one card
    // permanently tinted, so the set is re-asserted from scratch.
    scope.on(document, 'livewire:update', () => {
        cardRect = null

        if (card && !card.isConnected) {
            card = null
        }

        unlightCards(card)
    })

    // A navigation should take the whole field with it immediately rather than
    // leaving a lit card behind until the next page finishes booting.
    scope.on(document, 'livewire:navigating', hide)

    // No settle-back tweens on teardown, deliberately: creating tweens while the
    // runtime's context is mid-revert would leave them running after the context
    // stopped tracking them, and the nodes they would animate are removed by the
    // disposers above anyway. The cards just lose their attributes, which takes
    // the gradient with them in the same frame.
    scope.add(() => forgetCards())
}

/* ------------------------------------------------------------------------- */
/* Helpers                                                                    */
/* ------------------------------------------------------------------------- */

/**
 * Whether a `z-index: -1` child of <body> would actually be visible.
 *
 * The glow relies on CSS background propagation: <html> has no background of
 * its own, so `.fi-body`'s colour is handed to the canvas and <body> itself
 * paints nothing -- which leaves the negative-z-index layer sitting on the app
 * background and underneath every card. Give <html> a background and that stops
 * being true: body's colour then paints in its own step, which comes AFTER
 * negative-z-index descendants, and the glow is buried.
 *
 * That is exactly what a future theme might do, so it is checked rather than
 * assumed -- and the answer is "build no glow at all" rather than "force <html>
 * transparent", because overriding a background someone deliberately set would
 * be this module changing how the panel looks, which is not its job. One read,
 * once per matchMedia branch.
 */
function canPaintBehindContent() {
    const root = document.documentElement

    if (!root) {
        return false
    }

    const style = getComputedStyle(root)
    const color = style.backgroundColor

    return (
        style.backgroundImage === 'none' &&
        (color === 'transparent' || color === 'rgba(0, 0, 0, 0)' || color === '')
    )
}

function makeNode(className) {
    const node = document.createElement('div')

    node.className = className
    node.setAttribute(NODE_ATTR, '')
    node.setAttribute('aria-hidden', 'true')

    return node
}

/**
 * What is under the pointer, from the deepest element outwards.
 *
 * Walking rather than two `closest()` calls, because the two families nest both
 * ways round: Filament's date picker puts a button inside an input group, and a
 * repeater puts inputs inside a draggable item that is itself a button. Two
 * `closest()` calls would return whichever family was asked about first, not
 * whichever element is nearest -- and nearest is what the pointer is on.
 */
function stateFor(node) {
    let element = node
    let depth = 0

    while (element && element.nodeType === 1 && depth < WALK_LIMIT) {
        if (element === document.body) {
            break
        }

        if (typeof element.matches === 'function') {
            if (element.matches(TEXT_ENTRY)) {
                return isDisabled(element) ? 'idle' : 'text'
            }

            if (element.matches(INTERACTIVE)) {
                if (isDisabled(element)) {
                    return 'idle'
                }

                return isDestructive(element) ? 'danger' : 'hot'
            }
        }

        element = element.parentElement
        depth += 1
    }

    return 'idle'
}

function isDisabled(element) {
    return (
        element.hasAttribute('disabled') ||
        element.getAttribute('aria-disabled') === 'true' ||
        element.classList.contains('fi-disabled')
    )
}

function isDestructive(element) {
    if (element.classList.contains('fi-color-danger')) {
        return true
    }

    // Sliced because `textContent` on a wrapping <a> or <label> can return a
    // whole paragraph, and an action label is never anywhere near this long.
    const label = (element.textContent || '').slice(0, 80)
    const intent = element.getAttribute('wire:click') || ''

    return DESTRUCTIVE.test(label) || DESTRUCTIVE.test(intent)
}

/**
 * Drop the lit flag from every card except the one given. Queried rather than
 * tracked, so a card lit by a run that was interrupted -- or one whose flag
 * survived a Livewire morph -- is cleaned up too.
 */
function unlightCards(except) {
    document.querySelectorAll('[' + GLOW_ON_ATTR + ']').forEach((element) => {
        if (element !== except) {
            element.removeAttribute(GLOW_ON_ATTR)
        }
    })
}

/**
 * Remove every trace of the card highlight. Unlike the marker attributes in
 * interactions.js, these are safe to take away: no keyframe animation hangs off
 * them, so removing one cannot restart anything.
 */
function forgetCards() {
    document.querySelectorAll('[' + GLOW_ATTR + '], [' + GLOW_ON_ATTR + ']').forEach((element) => {
        element.removeAttribute(GLOW_ATTR)
        element.removeAttribute(GLOW_ON_ATTR)
        element.style.removeProperty('--vf-cursor-cx')
        element.style.removeProperty('--vf-cursor-cy')
    })
}

function injectStylesheet(scope) {
    const existing = document.getElementById(STYLE_ID)

    if (existing) {
        existing.remove()
    }

    const style = document.createElement('style')

    style.id = STYLE_ID
    style.textContent = CSS
    document.head.appendChild(style)

    scope.add(() => style.remove())
}

/**
 * A bag of teardown callbacks. Every listener and every appended node in this
 * file goes through one, so nothing can survive a context revert.
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
            disposers.push(disposer)
        },
        dispose() {
            while (disposers.length) {
                try {
                    disposers.pop()()
                } catch (error) {
                    // A failing disposer must not strand the ones behind it.
                    console.warn('[vpn-forge motion] cursor cleanup failed', error)
                }
            }
        },
    }
}
