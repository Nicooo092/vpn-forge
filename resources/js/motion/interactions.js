/*
 * Pointer-level micro-interactions
 * --------------------------------
 * Everything in this module happens under the operator's cursor: buttons that
 * lean toward the pointer, light that sweeps across a surface once, a sidebar
 * marker that travels between pages instead of teleporting, a rail that grows
 * on the row you are reading. None of it carries information -- the rest of the
 * motion layer does that -- so all of it is subordinate to one rule: a value an
 * operator is trying to read must never move, blur, or be covered.
 *
 * Three decisions shape the whole file.
 *
 * 1. EVERYTHING IS DELEGATED. Not a single listener is attached to a button, a
 *    card or a row. This panel re-renders constantly (tables poll, modals mount
 *    later, Livewire morphs sections in place), so per-element listeners would
 *    either miss half the DOM or accumulate one dead closure per replaced node.
 *    One `pointerover`/`pointermove` pair on `document` covers elements that do
 *    not exist yet and cannot leak.
 *
 * 2. NOTHING IS INSERTED INTO MARKUP LIVEWIRE OWNS. Sheens, spotlights and rails
 *    are all pseudo-elements defined in a stylesheet this module injects, driven
 *    from JS through CSS custom properties. Injecting real nodes into a button
 *    or a table row would put them in the path of Livewire's morph. The one
 *    exception is the sidebar indicator, which has to be a real element because
 *    it is FLIPped between positions -- and it is re-asserted if a morph eats it.
 *
 * 3. TRANSFORMS ARE OWNED BY GSAP OR BY CSS, NEVER BOTH. motion.css puts a
 *    180ms transition on `.fi-btn` transforms. Left in place, every frame GSAP
 *    writes would start its own interpolation and the button would swim behind
 *    the cursor. Elements GSAP takes over are marked `data-vf-pointer`, and the
 *    injected stylesheet drops `transform` from their transition list.
 *
 * Cleanup: gsap.context() reverts tweens, not listeners. A nested context is
 * created here purely so its revert -- triggered by the runtime's -- runs the
 * disposer that removes every listener, style tag and attribute this file added.
 */

import { Flip } from 'gsap/Flip'

const STYLE_ID = 'vf-interactions'

/** Maximum pull, in px, of a magnetic button. Small on purpose: this is a hint
 *  that the control noticed you, not a control that runs away from you. */
const MAGNET_PULL = 7

/** Resting lift applied while a magnetic button is hovered, replacing the CSS
 *  `:hover` translate that our own inline transform overrides. */
const MAGNET_LIFT = 1.5

/** Peak tilt of a stat card, in degrees. Past ~2deg a dashboard reads as wobbly
 *  rather than dimensional, and the numbers start to shimmer. */
const CARD_TILT = 1.6

/**
 * Actions that destroy something. Filament renders most of them with the danger
 * colour, which is already excluded, but a project can hand a destructive action
 * any colour it likes -- so the label and the Livewire target are checked too.
 * A "Create" button that leans toward the cursor is charming. A "Delete" button
 * that leans toward the cursor is a way to lose a server. Includes the French
 * verbs because this panel's UI is French.
 */
const DESTRUCTIVE =
    /delete|destroy|revoke|restore|detach|dissociate|prune|truncate|terminate|purge|supprim|r[ée]voqu|r[ée]initialis|d[ée]sactiv|arr[êe]t|r[ée]sili|d[ée]tach/i

/**
 * Where the sidebar indicator was when this module last ran. Module scope, so it
 * survives the runtime's teardown/boot cycle -- which is the whole point: on
 * `livewire:navigated` the context is reverted and everything is rebuilt, and
 * this is the only thing that lets the new indicator know where the old one was
 * and travel from there.
 */
let rememberedIndicator = null

const CSS = `
/* Registered so the values are typed: GSAP reads the computed value before it
   tweens, and an unregistered custom property reads back as an empty string. */
@property --vf-sheen { syntax: '<number>'; inherits: false; initial-value: 0; }
@property --vf-glow { syntax: '<number>'; inherits: false; initial-value: 0; }
@property --vf-mx { syntax: '<percentage>'; inherits: false; initial-value: 50%; }
@property --vf-my { syntax: '<percentage>'; inherits: false; initial-value: 50%; }
@property --vf-rx { syntax: '<percentage>'; inherits: false; initial-value: 50%; }

/* Tints only, no new palette: every colour below is Filament's own primary
   scale, so this stays inside the panel's native look. */
:root {
    --vf-sheen-tint: rgb(255 255 255 / 0.3);
    --vf-card-glow: color-mix(in oklab, var(--primary-500, #6366f1) 18%, transparent);
    --vf-row-glow: color-mix(in oklab, var(--primary-500, #6366f1) 9%, transparent);
    --vf-rail: var(--primary-600, #4f46e5);
}

.dark {
    --vf-sheen-tint: rgb(255 255 255 / 0.16);
    --vf-card-glow: color-mix(in oklab, var(--primary-400, #818cf8) 14%, transparent);
    --vf-row-glow: color-mix(in oklab, var(--primary-400, #818cf8) 8%, transparent);
    --vf-rail: var(--primary-400, #818cf8);
}

/* GSAP owns the transform of anything marked here, so the CSS transitions on
   transform step aside -- both Filament's own (\`.fi-btn\` transitions transform,
   translate, scale and rotate at 75ms) and motion.css's 180ms one. Left in
   place, every frame GSAP writes would start an interpolation of its own and
   the control would swim behind the cursor. Colour and shadow transitions stay:
   those are still the stylesheet's job. */
.fi-btn[data-vf-pointer],
.fi-icon-btn[data-vf-pointer],
.fi-wi-stats-overview-stat[data-vf-pointer] {
    transition-property:
        color, background-color, border-color, outline-color, fill, stroke,
        box-shadow, filter, opacity;
}

/* The entrance keyframes in motion.css run with \`animation-fill-mode: both\`,
   so the rule keeps applying \`transform: none\` forever after the animation
   ends -- and a CSS animation outranks inline styles, which would silently
   erase every transform GSAP writes to a stat card. Higher specificity turns it
   off. The attribute is only added on first hover, long after the entrance has
   played, and is deliberately never removed again: putting \`animation\` back
   would restart it. */
.fi-wi-stats-overview-stat[data-vf-pointer] {
    animation: none;
}

/* --- Sheen -----------------------------------------------------------------
   One pass of light across a solid button as the pointer arrives. The band
   lives inside a background image 2.6x the button's width, so sweeping the
   background position carries it on from one edge and off the other; at rest
   (--vf-sheen: 0) it is parked outside the box and paints nothing.           */

/* Filament already sets this (its badge container is absolutely positioned
   against the button); restated so the sheen cannot be broken by a future
   upstream change without the sheen rule visibly moving with it. */
.fi-btn[data-vf-pointer] {
    position: relative;
}

.fi-btn[data-vf-pointer]::after {
    content: '';
    position: absolute;
    inset: 0;
    border-radius: inherit;
    pointer-events: none;
    background-image: linear-gradient(
        105deg,
        transparent 41%,
        var(--vf-sheen-tint) 50%,
        transparent 59%
    );
    background-repeat: no-repeat;
    background-size: 260% 100%;
    background-position: calc(100% - var(--vf-sheen) * 100%) 0;
}

/* --- Stat card spotlight ---------------------------------------------------
   A soft light that tracks the pointer across the card, under the numbers.   */

.fi-wi-stats-overview-stat[data-vf-pointer]::after {
    content: '';
    position: absolute;
    inset: 0;
    border-radius: inherit;
    pointer-events: none;
    background-image: radial-gradient(
        16rem circle at var(--vf-mx) var(--vf-my),
        var(--vf-card-glow),
        transparent 68%
    );
    opacity: var(--vf-glow);
    transition: opacity 260ms var(--vf-ease, ease);
}

/* The value is the reason the card exists, so it sits above the light. */
.fi-wi-stats-overview-stat[data-vf-pointer] > .fi-wi-stats-overview-stat-content {
    position: relative;
    z-index: 1;
}

/* --- Table rows ------------------------------------------------------------
   A rail that grows from the row's leading edge, plus light that follows the
   pointer along it. Neither one moves a cell: an operator reading an IP address
   out of a row must not have it shift under the cursor. That is also why the
   row's content is not scaled -- the elevation is drawn, not applied.

   The rail hangs off the first cell rather than the <tr>, which is exactly
   where Filament puts its own selected-row marker, so the two land together
   instead of fighting. It is pure CSS on purpose: rows are re-created on every
   30s poll, and anything holding JS state per row would be stale immediately. */

.fi-ta-table tbody .fi-ta-row:not(.fi-ta-group-header-row) > :first-child {
    position: relative;
}

.fi-ta-table tbody .fi-ta-row:not(.fi-ta-group-header-row) > :first-child::after {
    content: '';
    position: absolute;
    inset-block: 0;
    inset-inline-start: 0;
    width: 2px;
    border-radius: 2px;
    background-color: var(--vf-rail);
    pointer-events: none;
    opacity: 0;
    transform: scaleY(0);
    transition:
        transform var(--vf-hover, 180ms) var(--vf-ease, ease),
        opacity var(--vf-hover, 180ms) var(--vf-ease, ease);
}

.fi-ta-table tbody .fi-ta-row:not(.fi-ta-group-header-row):hover > :first-child::after {
    opacity: 1;
    transform: scaleY(1);
}

.fi-ta-row[data-vf-hot] {
    background-image: radial-gradient(
        22rem circle at var(--vf-rx) 50%,
        var(--vf-row-glow),
        transparent 62%
    );
}

/* --- Sidebar indicator -----------------------------------------------------
   Position and size come from JS; this only describes what it looks like.    */

.vf-nav-indicator {
    position: absolute;
    width: 3px;
    border-radius: 999px;
    background-color: var(--vf-rail);
    box-shadow: 0 0 12px -2px var(--vf-rail);
    pointer-events: none;
    opacity: 0;
    z-index: 1;
}
`

/**
 * Pointer-level micro-interactions.
 *
 * @param {{gsap: import('gsap').gsap, MOTION: object}} api
 */
export function interactions({ gsap, MOTION }) {
    if (typeof document === 'undefined' || !document.body) {
        return null
    }

    gsap.registerPlugin(Flip)

    const clamp = gsap.utils.clamp

    /*
     * `gsap.context()` with no argument returns the context we are currently
     * running inside -- the runtime's. Tweens created later, from a pointer
     * handler, would otherwise be born with no context at all: nothing would
     * revert them, and the inline transforms they left behind would survive the
     * next navigation frozen mid-animation. Routing them through `add()` puts
     * them back under the runtime's control.
     */
    const host = gsap.context()

    /**
     * Runs `fn` as part of the runtime's context and hands back its result.
     * The result is fished out through a closure rather than returned directly
     * because `context.add()` treats a returned *function* as a cleanup callback
     * and would invoke it on revert -- and `gsap.quickTo()` returns a function.
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

    const write = createWriter(scope)
    const rectOf = createRectCache(scope)

    pressFeedback({ gsap, MOTION, clamp, scope, owned })
    sidebarIndicator({ gsap, MOTION, scope, owned })

    // Cursor-reactive work only. On a touch device there is no cursor to react
    // to, so none of it would ever fire -- it would just be listeners riding
    // along on a phone.
    gsap.matchMedia().add('(pointer: fine)', () => {
        const fine = createScope()

        cursorSurfaces({ gsap, MOTION, clamp, scope: fine, owned, write, rectOf })

        return () => fine.dispose()
    })

    return null
}

/* ------------------------------------------------------------------------- */
/* Lifecycle helpers                                                          */
/* ------------------------------------------------------------------------- */

/**
 * A bag of teardown callbacks. Every listener, timer and DOM insertion in this
 * file goes through one of these so nothing survives a context revert.
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
                    console.warn('[vpn-forge motion] interactions cleanup failed', error)
                }
            }
        },
    }
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

    // The custom properties go, but `data-vf-pointer` deliberately stays. It is
    // inert once the stylesheet above is gone, and removing it would put
    // motion.css's entrance keyframes back on any stat card a Livewire morph
    // preserved -- which replays the rise animation on a card that never went
    // anywhere. `data-vf-hot` has no such rule behind it and is safe to clear.
    scope.add(() => {
        document.querySelectorAll('[data-vf-pointer], [data-vf-hot]').forEach((element) => {
            element.removeAttribute('data-vf-hot')
            element.style.removeProperty('--vf-sheen')
            element.style.removeProperty('--vf-glow')
            element.style.removeProperty('--vf-mx')
            element.style.removeProperty('--vf-my')
            element.style.removeProperty('--vf-rx')
        })
    })
}

/**
 * Coalesces custom-property writes into one animation frame. Pointer handlers
 * only ever write; the single read they need (the element's box) comes from the
 * cache below. Keeping the two apart is what stops a pointermove from forcing a
 * synchronous layout on every frame.
 */
function createWriter(scope) {
    const queue = new Map()
    let frame = 0

    const flush = () => {
        frame = 0

        queue.forEach((props, element) => {
            if (!element.isConnected) {
                return
            }

            Object.keys(props).forEach((name) => {
                const value = props[name]

                if (value === null) {
                    element.style.removeProperty(name)
                } else {
                    element.style.setProperty(name, value)
                }
            })
        })

        queue.clear()
    }

    scope.add(() => {
        if (frame) {
            cancelAnimationFrame(frame)
        }

        frame = 0
        queue.clear()
    })

    return (element, props) => {
        const queued = queue.get(element)

        if (queued) {
            Object.assign(queued, props)
        } else {
            queue.set(element, Object.assign({}, props))
        }

        if (!frame) {
            frame = requestAnimationFrame(flush)
        }
    }
}

/**
 * Caches bounding boxes per element and throws the cache away whenever anything
 * could have moved. Measuring on every pointermove would be a forced layout per
 * frame; measuring once per hover and trusting it forever would put the magnet
 * a screen away after a scroll.
 */
function createRectCache(scope) {
    const cache = new WeakMap()
    let generation = 1

    const invalidate = () => {
        generation += 1
    }

    // Scroll does not bubble, but it does reach a capturing listener on window,
    // which is how nested scrollers (tables, the sidebar) are covered too.
    scope.on(window, 'scroll', invalidate, { passive: true, capture: true })
    scope.on(window, 'resize', invalidate, { passive: true })

    return (element) => {
        const hit = cache.get(element)

        if (hit && hit.generation === generation) {
            return hit.rect
        }

        const rect = element.getBoundingClientRect()
        cache.set(element, { rect, generation })

        return rect
    }
}

function isDisabled(element) {
    return (
        element.hasAttribute('disabled') ||
        element.getAttribute('aria-disabled') === 'true' ||
        element.classList.contains('fi-disabled')
    )
}

/* ------------------------------------------------------------------------- */
/* Press feedback                                                             */
/* ------------------------------------------------------------------------- */

/**
 * A press that starts where the pointer landed. Scaling from the centre reads as
 * the button shrinking; scaling from the contact point reads as the button
 * yielding under your finger, which is the whole difference. Not gated on
 * pointer type -- a touch press deserves the same acknowledgement.
 */
function pressFeedback({ gsap, MOTION, clamp, scope, owned }) {
    const PRESSABLE = '.fi-btn, .fi-icon-btn'
    let pressed = null

    const release = () => {
        if (!pressed) {
            return
        }

        const element = pressed
        pressed = null

        owned(() =>
            gsap.to(element, {
                scale: 1,
                duration: MOTION.base,
                ease: MOTION.spring,
                overwrite: 'auto',
                onComplete: () => gsap.set(element, { clearProps: 'willChange' }),
            }),
        )
    }

    scope.on(
        document,
        'pointerdown',
        (event) => {
            // Secondary buttons open context menus; they are not a press.
            if (event.button > 0) {
                return
            }

            const element = event.target && event.target.closest && event.target.closest(PRESSABLE)

            if (!element || isDisabled(element)) {
                return
            }

            release()
            pressed = element
            element.setAttribute('data-vf-pointer', '')

            const rect = element.getBoundingClientRect()
            const originX = rect.width ? ((event.clientX - rect.left) / rect.width) * 100 : 50
            const originY = rect.height ? ((event.clientY - rect.top) / rect.height) * 100 : 50

            owned(() => {
                // Set rather than tweened: the origin has to be in place before
                // the first frame of the scale, or the press starts from the
                // middle and slides.
                gsap.set(element, {
                    transformOrigin: `${clamp(0, 100, originX)}% ${clamp(0, 100, originY)}%`,
                    willChange: 'transform',
                })

                return gsap.to(element, {
                    scale: 0.965,
                    duration: 0.11,
                    ease: 'power2.out',
                    overwrite: 'auto',
                })
            })
        },
        { passive: true },
    )

    scope.on(document, 'pointerup', release, { passive: true })
    scope.on(document, 'pointercancel', release, { passive: true })

    // Dragging off the control, or tabbing away mid-press, has to release it too
    // -- otherwise a half-pressed button is left sitting on screen.
    scope.on(document, 'pointerleave', release, { passive: true })
    scope.on(window, 'blur', release)
}

/* ------------------------------------------------------------------------- */
/* Cursor-reactive surfaces                                                   */
/* ------------------------------------------------------------------------- */

/**
 * Magnetic buttons, the sheen sweep, stat-card tilt and spotlight, and the row
 * light. All three families share one pointerover/pointermove pair: the handler
 * costs three null checks when nothing is hovered, and no element anywhere holds
 * a listener of its own.
 */
function cursorSurfaces({ gsap, MOTION, clamp, scope, owned, write, rectOf }) {
    const magnets = new WeakMap()
    const tilts = new WeakMap()

    let hotButton = null
    let hotCard = null
    let hotRow = null

    /* --- magnets --------------------------------------------------------- */

    const isMagnetic = (button) => {
        // Only solid primary buttons. Danger buttons carry `fi-color-danger`
        // and are excluded by this alone; the label check below is the second
        // line of defence for destructive actions wearing a primary colour.
        if (!button.classList.contains('fi-color-primary') || isDisabled(button)) {
            return false
        }

        const intent = `${button.getAttribute('wire:click') || ''} ${button.id || ''} ${button.textContent || ''}`

        return !DESTRUCTIVE.test(intent)
    }

    const magnetFor = (button) => {
        let magnet = magnets.get(button)

        if (!magnet) {
            magnet = owned(() => ({
                x: gsap.quickTo(button, 'x', { duration: 0.5, ease: MOTION.easeSoft }),
                y: gsap.quickTo(button, 'y', { duration: 0.5, ease: MOTION.easeSoft }),
            }))

            magnets.set(button, magnet)
        }

        return magnet
    }

    const enterButton = (button) => {
        button.setAttribute('data-vf-pointer', '')

        // A sheen reads as light on a coloured surface. On an outlined or plain
        // button there is no surface for it to land on, so it is skipped.
        if (button.classList.contains('fi-color') && !button.classList.contains('fi-outlined')) {
            owned(() =>
                gsap.fromTo(
                    button,
                    { '--vf-sheen': 0 },
                    {
                        '--vf-sheen': 1,
                        duration: MOTION.slow * 0.55,
                        ease: 'power2.inOut',
                        overwrite: 'auto',
                        onComplete: () => button.style.removeProperty('--vf-sheen'),
                    },
                ),
            )
        }

        if (isMagnetic(button)) {
            magnetFor(button)
            owned(() => gsap.set(button, { willChange: 'transform' }))
        }
    }

    const trackButton = (event) => {
        if (!hotButton.isConnected) {
            hotButton = null
            return
        }

        const magnet = magnets.get(hotButton)

        if (!magnet) {
            return
        }

        const rect = rectOf(hotButton)

        if (!rect.width || !rect.height) {
            return
        }

        const dx = event.clientX - (rect.left + rect.width / 2)
        const dy = event.clientY - (rect.top + rect.height / 2)

        // The pull is capped both as a fraction of the button and in absolute
        // px, so a wide toolbar button does not slide further than a small one.
        const limitX = Math.min(rect.width * 0.18, MAGNET_PULL)
        const limitY = Math.min(rect.height * 0.22, MAGNET_PULL * 0.6)

        magnet.x(clamp(-limitX, limitX, dx * 0.32))
        magnet.y(clamp(-limitY, limitY, dy * 0.32) - MAGNET_LIFT)
    }

    const leaveButton = (button) => {
        const magnet = magnets.get(button)

        if (!magnet) {
            return
        }

        magnet.x(0)
        magnet.y(0)

        // will-change is a promise to the compositor. Left set on every button
        // the pointer has ever crossed, a busy page ends up holding dozens of
        // layers it no longer needs.
        owned(() =>
            gsap.delayedCall(0.6, () => {
                if (button !== hotButton) {
                    gsap.set(button, { clearProps: 'willChange' })
                }
            }),
        )
    }

    /* --- stat cards ------------------------------------------------------ */

    const tiltFor = (card) => {
        let tilt = tilts.get(card)

        if (!tilt) {
            tilt = owned(() => ({
                x: gsap.quickTo(card, 'rotationX', { duration: 0.55, ease: MOTION.easeSoft }),
                y: gsap.quickTo(card, 'rotationY', { duration: 0.55, ease: MOTION.easeSoft }),
            }))

            tilts.set(card, tilt)
        }

        return tilt
    }

    const enterCard = (card) => {
        card.setAttribute('data-vf-pointer', '')
        tiltFor(card)

        owned(() => {
            gsap.set(card, { transformPerspective: 900, willChange: 'transform' })

            return gsap.to(card, {
                y: -3,
                duration: MOTION.fast,
                ease: MOTION.easeSoft,
                overwrite: 'auto',
            })
        })

        write(card, { '--vf-glow': '1' })
    }

    const trackCard = (event) => {
        if (!hotCard.isConnected) {
            hotCard = null
            return
        }

        const rect = rectOf(hotCard)

        if (!rect.width || !rect.height) {
            return
        }

        const nx = (event.clientX - rect.left) / rect.width
        const ny = (event.clientY - rect.top) / rect.height
        const tilt = tiltFor(hotCard)

        tilt.y(clamp(-CARD_TILT, CARD_TILT, (nx - 0.5) * CARD_TILT * 2))
        tilt.x(clamp(-CARD_TILT, CARD_TILT, (0.5 - ny) * CARD_TILT * 2))

        write(hotCard, {
            '--vf-mx': `${clamp(0, 100, nx * 100).toFixed(2)}%`,
            '--vf-my': `${clamp(0, 100, ny * 100).toFixed(2)}%`,
        })
    }

    const leaveCard = (card) => {
        const tilt = tilts.get(card)

        if (tilt) {
            tilt.x(0)
            tilt.y(0)
        }

        write(card, { '--vf-glow': '0' })

        owned(() =>
            gsap.to(card, {
                y: 0,
                duration: MOTION.base,
                ease: MOTION.spring,
                overwrite: 'auto',
                onComplete: () => {
                    if (card !== hotCard) {
                        gsap.set(card, { clearProps: 'willChange' })
                    }
                },
            }),
        )
    }

    /* --- table rows ------------------------------------------------------ */

    const trackRow = (event) => {
        if (!hotRow.isConnected) {
            hotRow = null
            return
        }

        const rect = rectOf(hotRow)

        if (!rect.width) {
            return
        }

        write(hotRow, {
            '--vf-rx': `${clamp(0, 100, ((event.clientX - rect.left) / rect.width) * 100).toFixed(2)}%`,
        })
    }

    /* --- dispatch -------------------------------------------------------- */

    const clearAll = (animate) => {
        if (hotButton) {
            if (animate) {
                leaveButton(hotButton)
            }

            hotButton = null
        }

        if (hotCard) {
            if (animate) {
                leaveCard(hotCard)
            }

            hotCard = null
        }

        if (hotRow) {
            hotRow.removeAttribute('data-vf-hot')
            hotRow = null
        }
    }

    // On teardown the settle-back tweens are deliberately skipped. Creating
    // tweens while the runtime's context is mid-revert would leave them running
    // after the context stopped tracking them -- and they are pointless anyway,
    // because the revert restores every inline transform they would animate to.
    scope.add(() => clearAll(false))

    scope.on(
        document,
        'pointerover',
        (event) => {
            // A pen behaves like a mouse here; a finger has no hover state at
            // all and would leave every surface it touched permanently lit.
            if (event.pointerType === 'touch') {
                return
            }

            const target = event.target

            if (!target || !target.closest) {
                return
            }

            const button = target.closest('.fi-btn')

            if (button !== hotButton) {
                if (hotButton) {
                    leaveButton(hotButton)
                }

                hotButton = button && !isDisabled(button) ? button : null

                if (hotButton) {
                    enterButton(hotButton)
                }
            }

            const card = target.closest('.fi-wi-stats-overview-stat')

            if (card !== hotCard) {
                if (hotCard) {
                    leaveCard(hotCard)
                }

                hotCard = card

                if (hotCard) {
                    enterCard(hotCard)
                }
            }

            const row = target.closest('.fi-ta-table tbody .fi-ta-row')

            if (row !== hotRow) {
                if (hotRow) {
                    hotRow.removeAttribute('data-vf-hot')
                }

                hotRow = row && !row.classList.contains('fi-ta-group-header-row') ? row : null

                if (hotRow) {
                    hotRow.setAttribute('data-vf-hot', '')
                }
            }
        },
        { passive: true },
    )

    scope.on(
        document,
        'pointermove',
        (event) => {
            if (event.pointerType === 'touch') {
                return
            }

            if (hotButton) {
                trackButton(event)
            }

            if (hotCard) {
                trackCard(event)
            }

            if (hotRow) {
                trackRow(event)
            }
        },
        { passive: true },
    )

    // Leaving the document entirely never produces a pointerover for something
    // else, so nothing would ever be released.
    scope.on(
        document,
        'pointerout',
        (event) => {
            if (!event.relatedTarget) {
                clearAll(true)
            }
        },
        { passive: true },
    )

    scope.on(window, 'blur', () => clearAll(true))
}

/* ------------------------------------------------------------------------- */
/* Sidebar indicator                                                          */
/* ------------------------------------------------------------------------- */

/**
 * One marker that travels between navigation items instead of the active state
 * hard-swapping from one item to the next.
 *
 * Two things make it feel continuous rather than animated:
 *
 *   - It moves on *click*, before the page has loaded. The panel commits to
 *     where you are going the moment you ask, which is most of the reason SPA
 *     navigation here stops feeling like a page load.
 *   - Its position is remembered in module scope across the runtime's
 *     teardown/boot cycle, so when the module is rebuilt after
 *     `livewire:navigated` the new marker starts where the old one stood and
 *     travels from there.
 *
 * The travel itself is FLIP: `top`/`height` are set to the destination in one
 * write, and Flip animates the difference on the compositor. Tweening top and
 * height directly would be a layout pass per frame on a sidebar that is often
 * the tallest element on the page.
 */
function sidebarIndicator({ gsap, MOTION, scope, owned }) {
    const nav = document.querySelector('.fi-sidebar-nav')

    if (!nav) {
        return
    }

    const activeButton = () => {
        // Note: `.fi-sidebar-item-btn`, not `-button`. Sub-items nest, so the
        // deepest active one is the real destination.
        const buttons = nav.querySelectorAll('.fi-sidebar-item.fi-active > .fi-sidebar-item-btn')

        return buttons.length ? buttons[buttons.length - 1] : null
    }

    let active = activeButton()

    if (!active) {
        return
    }

    const indicator = document.createElement('span')
    indicator.className = 'vf-nav-indicator'
    indicator.setAttribute('aria-hidden', 'true')
    nav.appendChild(indicator)
    scope.add(() => indicator.remove())

    // The nav becomes the positioning context. It scrolls, and an absolutely
    // positioned child scrolls with its content, which is why the marker stays
    // glued to its item in a long sidebar without a scroll listener.
    gsap.set(nav, { position: 'relative' })

    const measure = (button) => {
        if (!button || !button.isConnected || !nav.isConnected) {
            return null
        }

        const navRect = nav.getBoundingClientRect()
        const rect = button.getBoundingClientRect()

        if (!rect.height) {
            return null
        }

        const rtl = getComputedStyle(nav).direction === 'rtl'
        const gutter = rtl ? navRect.right - rect.right : rect.left - navRect.left

        return {
            top: Math.round(rect.top - navRect.top + nav.scrollTop),
            height: Math.round(rect.height),
            // Sits in the nav's padding gutter, just outside the item.
            inline: Math.max(2, Math.round(gutter - 12)),
            rtl,
        }
    }

    const place = (geometry) => {
        gsap.set(indicator, {
            top: geometry.top,
            height: geometry.height,
            left: geometry.rtl ? 'auto' : geometry.inline,
            right: geometry.rtl ? geometry.inline : 'auto',
            autoAlpha: 1,
        })
    }

    const moveTo = (geometry, animate) => {
        if (!geometry) {
            return
        }

        if (!animate) {
            place(geometry)

            owned(() =>
                gsap.fromTo(
                    indicator,
                    { scaleY: 0.3, autoAlpha: 0 },
                    {
                        scaleY: 1,
                        autoAlpha: 1,
                        duration: MOTION.base,
                        ease: MOTION.ease,
                        transformOrigin: '50% 50%',
                        overwrite: 'auto',
                    },
                ),
            )

            return
        }

        const state = Flip.getState(indicator)
        place(geometry)

        // scale: true keeps the height change on scaleY instead of animating
        // the height property frame by frame.
        owned(() =>
            Flip.from(state, {
                duration: MOTION.base,
                ease: MOTION.ease,
                scale: true,
            }),
        )
    }

    const geometry = measure(active)

    if (!geometry) {
        return
    }

    if (rememberedIndicator && rememberedIndicator.top !== geometry.top) {
        place(rememberedIndicator)
        moveTo(geometry, true)
    } else {
        moveTo(geometry, false)
    }

    rememberedIndicator = geometry

    scope.on(nav, 'click', (event) => {
        const button = event.target && event.target.closest && event.target.closest('.fi-sidebar-item-btn')

        if (!button || !nav.contains(button)) {
            return
        }

        // A modified click or a new-tab link leaves this page where it is, so
        // moving the marker would be a lie about where the operator now is.
        if (button.target === '_blank' || event.metaKey || event.ctrlKey || event.shiftKey || event.button > 0) {
            return
        }

        const next = measure(button)

        if (!next || (rememberedIndicator && next.top === rememberedIndicator.top)) {
            return
        }

        active = button
        moveTo(next, true)
        rememberedIndicator = next
    })

    let resyncFrame = 0

    const resync = () => {
        if (resyncFrame) {
            return
        }

        resyncFrame = requestAnimationFrame(() => {
            resyncFrame = 0

            if (!nav.isConnected) {
                return
            }

            // Livewire re-renders the sidebar for badge polling, and its morph
            // removes DOM it did not render -- which includes this element.
            // Re-assert it rather than letting the active marker quietly
            // disappear halfway through a session.
            if (!indicator.isConnected) {
                nav.appendChild(indicator)
            }

            const current = measure(activeButton() || active)

            if (!current) {
                return
            }

            place(current)
            rememberedIndicator = current
        })
    }

    scope.add(() => {
        if (resyncFrame) {
            cancelAnimationFrame(resyncFrame)
        }
    })

    scope.on(window, 'resize', resync, { passive: true })
    scope.on(document, 'livewire:update', resync)

    // Collapsing the sidebar changes the items' width without firing a resize,
    // and web fonts land after first paint. One observer covers both.
    if (typeof ResizeObserver === 'function') {
        const observer = new ResizeObserver(resync)
        observer.observe(nav)
        scope.add(() => observer.disconnect())
    }
}
