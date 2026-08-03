/*
 * Physics and gesture
 * -------------------
 * Everything in this file is the panel answering a hand. Not a scroll position,
 * not a Livewire event -- a deliberate push, drag or throw, and the momentum it
 * carried:
 *
 *   - a wide log table can be grabbed and thrown sideways, with a fling that
 *     decays like a real object and a rubber band when it runs out of table;
 *   - a wheel or trackpad pushed past the end of that table makes the card lean
 *     and spring back, which is how an operator finds out there is no more;
 *   - a table that scrolls sideways gives one small lateral nudge the first time
 *     it comes into view, so the operator finds out it can be grabbed at all;
 *   - a toast can be swiped away, in either direction, with the throw finishing
 *     the dismissal Filament's own close button would have;
 *   - and at the very top of a page of widgets, pushing further up pulls a
 *     refresh chip down out of the chrome and re-reads every widget.
 *
 * THE HARD RULE, WHICH OUTRANKS EVERY EFFECT BELOW: nothing here may hijack or
 * delay a normal scroll or a normal click. Every Observer is created with
 * `preventDefault: false`, which is what makes GSAP attach its listeners
 * passively -- so this module is structurally incapable of blocking a scroll,
 * not merely careful not to. Where a gesture needs the browser to stand aside
 * it says so declaratively in CSS (`touch-action: pan-y` on a toast) rather than
 * by cancelling events. And where the intent is ambiguous, the answer is always
 * to do nothing:
 *
 *   - a pan refuses to start if the browser has already begun selecting text
 *     under the pointer. That is not a heuristic about what the operator meant;
 *     it is the browser's own verdict, read at the instant we would take over.
 *     Dragging across an IP address to copy it keeps working exactly as it did.
 *   - a pan refuses touch outright. Touch scrolling already has momentum and a
 *     rubber band, from the compositor, on a thread we cannot stall. Replacing
 *     that with ours would be a downgrade in every measurable way.
 *   - the pull affordance is wheel-only. A touch pull-to-refresh has to cancel
 *     `touchmove` at the top of the document, and cancelling touchmove is
 *     precisely the thing this module is not allowed to do. A wheel at
 *     scrollTop 0 is different: the browser has nothing left to scroll, so the
 *     gesture is free.
 *
 * Nothing here is reachable by keyboard, and nothing here is the only way to do
 * anything: the table still scrolls with the wheel and the scrollbar, the toast
 * still has its close button in the tab order, the widgets still poll. A
 * keyboard or screen-reader user loses no capability by this file existing.
 *
 * WHAT THIS MODULE DELIBERATELY DOES NOT DO. The brief's other idea was
 * wheel-velocity parallax on the page. scroll.js already owns every scrubbed
 * layer of the page header and the section headers, and the depth module owns
 * the layers behind them; a third module writing transforms to the same nodes
 * would produce a fight nobody could debug. So scroll velocity is used here
 * where it is genuinely physics and genuinely ours -- how far a fling carries,
 * how hard the card leans at the edge, how fast the pull builds -- and the page
 * itself is left to the modules that own it.
 *
 * CONTAINING BLOCKS. A live transform makes its element the containing block
 * for `position: fixed` descendants, and `.fi-ta-ctn` renders the table's
 * filters modal inside itself. Every transform written here is therefore
 * transient: it exists only while a gesture is in flight, every path out of the
 * gesture clears it, `settleCard()` sweeps anything a killed tween left behind,
 * and teardown clears the lot by hand -- quickSetter writes are invisible to
 * gsap.context(), so they cannot be reverted for us.
 *
 * READABILITY. The only content this file ever fades is a toast the operator is
 * physically dragging off the screen, it never goes below 0.35 while it is
 * still there, the spring-back restores it, and a timed failsafe restores it
 * again if Filament never removed the card. Nothing else in here touches
 * opacity at all.
 */

import { Observer } from 'gsap/Observer'

const STYLE_ID = 'vf-physics'

/* Marks DOM this module appended. gsap.context() reverts tweens, not nodes, and
   the runtime discards our return value -- so each run sweeps the previous
   run's nodes by attribute before it builds anything. */
const OWNED_ATTR = 'data-vf-physics'

/* On the scroller for the length of a pan. The injected stylesheet keys the
   grab cursor and the selection lock off it, so a table nobody is dragging
   behaves exactly as Filament shipped it. */
const PAN_ATTR = 'data-vf-pan'

/* On a toast while we own its transform, so a second pointer cannot start a
   second swipe on the same card. */
const SWIPE_ATTR = 'data-vf-swiping'

/* --- Shared gesture thresholds -------------------------------------------
   Both horizontal gestures in this file -- the table pan and the toast swipe --
   have to answer the same question before they take anything: is this drag
   horizontal, and is the operator sure? One set of numbers, so the two feel
   like the same hand rather than two different ones.                        */

/* Travel before a gesture is allowed to call itself horizontal. Deliberately
   larger than Observer's own drag threshold: this is the distance at which the
   direction of a drag is actually knowable. */
const PAN_COMMIT = 10

/* How much more horizontal than vertical it has to be. A diagonal drag is an
   unclear drag, and an unclear drag is left to the browser. */
const AXIS_BIAS = 1.25

/* Vertical travel after which the gesture is definitely a scroll and can never
   become anything else, however far it later wanders sideways. */
const PAN_GIVE_UP = 26

/* --- Pan tuning ---------------------------------------------------------- */

/* Below this much hidden width there is nothing worth dragging to, and a pan
   would only be a way to lose a click. */
const PAN_MIN_OVERFLOW = 24

/* Ceiling on the rubber band, in px, and how fast it approaches that ceiling.
   Asymptotic rather than linear so pulling harder always does something and
   never does much. */
const RUBBER_MAX = 18
const RUBBER_SOFTNESS = 2.4

/* A fling's reach: pointer velocity (px/s) times this, capped. 0.26 lands a
   hard throw about a screen and a half further on, which is roughly what a
   flicked sheet of paper does. */
const FLING_REACH = 0.26
const FLING_MAX = 1400
const FLING_MIN_VELOCITY = 140
const GLIDE_MAX = 1.05

/* --- Wheel tuning -------------------------------------------------------- */

/* One edge bump per this many seconds. Without it, resting a trackpad against
   the end of a table would make the card twitch continuously. */
const BUMP_COOLDOWN = 0.7
const BUMP_MIN_DELTA = 6

/* --- Swipe tuning -------------------------------------------------------- */

/* A toast goes if it is dragged past a third of its own width, or thrown hard
   enough that where it currently is stops being the question. */
const SWIPE_SHARE = 0.32
const SWIPE_FLOOR = 60
const SWIPE_VELOCITY = 620
const SWIPE_VELOCITY_FLOOR = 24

/* The card never fades further than this while it is still on screen. */
const SWIPE_FADE_FLOOR = 0.35

/* --- Pull tuning --------------------------------------------------------- */

/**
 * Whether the primary pointer is a finger rather than a mouse or trackpad.
 * Read live rather than cached, because a hybrid laptop can switch between the
 * two without a reload.
 */
function coarsePointer() {
    return window.matchMedia('(pointer: coarse)').matches
}

const PULL_MAX = 92
const PULL_TRIGGER = 62
const PULL_RESISTANCE = 0.42
const PULL_COOLDOWN = 5

/* Controls a drag must never start from: their own gesture, their own drag, or
   a click we would be stealing. `img` is here because browsers make images
   natively draggable and the two gestures cannot both win. */
const NO_DRAG =
    'a[href], button, input, select, textarea, label, summary, img,' +
    ' [role="button"], [role="link"], [contenteditable=""], [contenteditable="true"],' +
    ' [draggable="true"], .fi-ta-reorder-handle, .fi-dropdown-panel'

const CSS = `
/* Only while a pan is actually under way. A resting \`grab\` cursor over a table
   would contradict the text caret an operator needs to select a value out of a
   row -- the affordance appears at the moment it becomes true and not before.
   \`cursor\` inherits, so the container covers its cells; the explicit entries
   are for the descendants that set a cursor of their own. */
.fi-ta-content-ctn[${PAN_ATTR}] {
    cursor: grabbing;
    user-select: none;
}

.fi-ta-content-ctn[${PAN_ATTR}] .fi-ta-row,
.fi-ta-content-ctn[${PAN_ATTR}] .fi-ta-cell,
.fi-ta-content-ctn[${PAN_ATTR}] .fi-ta-col {
    cursor: grabbing;
}

/* Hands the vertical axis to the browser outright and keeps the horizontal one
   for the swipe. This is what makes the swipe additive on touch: the page still
   scrolls under a finger resting on a toast, at compositor speed, with no
   listener of ours involved in the decision. \`pinch-zoom\` is named explicitly
   because omitting it would take magnification away from anyone whose fingers
   happen to land on a toast, and a decorative gesture does not get to do that. */
.fi-no .fi-no-notification:not(.fi-inline) {
    touch-action: pan-y pinch-zoom;
}

/* The pull chip. Filament's own surface tokens, so it reads as part of the
   panel rather than as something bolted on. z-index 35 matches the reading
   progress bar in scroll.js: above the sticky topbar (30), below a modal
   overlay (40). */
.vf-pull {
    position: fixed;
    inset-inline: 0;
    top: 0;
    display: flex;
    justify-content: center;
    z-index: 35;
    pointer-events: none;
    opacity: 0;
}

.vf-pull-chip {
    display: grid;
    place-items: center;
    width: 2.25rem;
    height: 2.25rem;
    margin-top: 0.75rem;
    border-radius: 9999px;
    background-color: #fff;
    color: var(--primary-600, #4f46e5);
    box-shadow: 0 4px 14px -3px rgb(0 0 0 / 0.25);
    outline: 1px solid rgb(0 0 0 / 0.05);
}

.dark .vf-pull-chip {
    background-color: var(--gray-900, #18181b);
    color: var(--primary-400, #818cf8);
    outline-color: rgb(255 255 255 / 0.1);
}

.vf-pull-chip svg {
    width: 1.15rem;
    height: 1.15rem;
}
`

const CHIP_HTML =
    '<div class="vf-pull-chip">' +
    '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"' +
    ' stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' +
    '<path d="M16.023 9.348h4.992V4.356"/>' +
    '<path d="M2.985 19.644v-4.992h4.992"/>' +
    '<path d="M2.985 14.652l3.181 3.183a8.25 8.25 0 0 0 13.803-3.7"/>' +
    '<path d="M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.183"/>' +
    '</svg>' +
    '</div>'

/**
 * Physics and gesture.
 *
 * @param {{gsap: import('gsap').gsap, ScrollTrigger: object, MOTION: object}} api
 */
export function physicsInteractions({ gsap, ScrollTrigger, MOTION }) {
    if (typeof document === 'undefined' || !document.body) {
        return null
    }

    // Nodes from a previous run that its disposers never got to -- an
    // interrupted boot, a navigation that landed mid-teardown.
    document.querySelectorAll(`[${OWNED_ATTR}]`).forEach((node) => node.remove())

    // The operator can stand the whole motion layer down from the console. A
    // gesture is direct manipulation rather than decoration, but "off" means
    // off, and every module in this system is told to consult the contract.
    if (!motionEnabled()) {
        return null
    }

    gsap.registerPlugin(Observer)

    const clamp = gsap.utils.clamp
    const scope = createScope()

    // gsap.context() with no argument hands back the context we are running
    // inside -- the runtime's. Tweens created later, from inside a gesture
    // handler, would otherwise be born with no context at all: nothing would
    // revert them and the inline transforms they left would survive the next
    // navigation frozen mid-flight.
    const host = gsap.context()

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

    // gsap.context() reverts tweens, not listeners, Observers or nodes. This
    // nested context exists only so the runtime's revert() reaches the disposer.
    gsap.context(() => () => scope.dispose())

    injectStylesheet(scope)

    /* Cards this module has written a transform to. quickSetter writes are not
       tweens, so the context cannot revert them -- teardown clears them here,
       by hand, or a card stays leaning for the rest of the session. */
    const leaned = new Set()

    const settleCard = (card) => {
        if (card && card.isConnected && !gsap.isTweening(card)) {
            gsap.set(card, { clearProps: 'transform,willChange' })
        }
    }

    scope.add(() => {
        leaned.forEach((card) => {
            if (card.isConnected) {
                gsap.set(card, { clearProps: 'transform,willChange' })
            }
        })

        leaned.clear()
    })

    /* Decorative extras -- the edge bump and the first-view nudge -- are the
       only things in here that run without being asked. They are the first to
       go when the governor says this machine has nothing to spare, and they go
       live too, because a verdict can change mid-session. */
    const extras = { on: motionAllows('physics') }

    const onTier = (event) => {
        const tier = event && event.detail && event.detail.tier

        if (tier === 'low') {
            extras.on = false
        }
    }

    scope.on(document, 'vf:motion-tier', onTier)

    const shared = { gsap, ScrollTrigger, MOTION, clamp, scope, owned, leaned, settleCard, extras }

    tablePan(shared)
    wheelPhysics(shared)
    toastSwipe(shared)

    return null
}

/* ------------------------------------------------------------------------- */
/* Lifecycle                                                                  */
/* ------------------------------------------------------------------------- */

/**
 * A bag of teardown callbacks. Every listener, Observer, timer and appended
 * node in this file goes through one, so nothing survives a context revert.
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
                    console.warn('[vpn-forge motion] physics cleanup failed', error)
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

    scope.add(() => {
        document.querySelectorAll(`[${PAN_ATTR}], [${SWIPE_ATTR}]`).forEach((element) => {
            element.removeAttribute(PAN_ATTR)
            element.removeAttribute(SWIPE_ATTR)
        })
    })
}

/**
 * The performance contract, read defensively -- it is published by a module
 * that runs first, but a page where that module threw still has to work.
 * Missing contract means yes, which is the behaviour a caller would have
 * written by hand.
 */
function motionAllows(feature) {
    const contract = typeof window !== 'undefined' ? window.__vfMotion : null

    if (!contract || typeof contract.allow !== 'function') {
        return true
    }

    try {
        return contract.allow(feature)
    } catch (error) {
        return true
    }
}

function motionEnabled() {
    const contract = typeof window !== 'undefined' ? window.__vfMotion : null

    if (!contract || typeof contract.atLeast !== 'function') {
        return true
    }

    try {
        return contract.atLeast('low')
    } catch (error) {
        return true
    }
}

/** True while a Filament modal is open anywhere on the page. */
function modalIsOpen() {
    return Boolean(document.querySelector('.fi-modal.fi-modal-open'))
}

/**
 * Hidden width of a horizontal scroller. One layout read, taken once per
 * gesture rather than once per frame.
 */
function overflowOf(scroller) {
    return Math.max(0, scroller.scrollWidth - scroller.clientWidth)
}

/**
 * Asymptotic resistance. However hard the operator pulls, the card never leans
 * further than `RUBBER_MAX` -- and every extra pixel of pull still moves it a
 * little, so the band never feels like it has simply stopped responding.
 */
function rubber(distance) {
    const sign = distance < 0 ? -1 : 1
    const magnitude = Math.abs(distance)

    return sign * RUBBER_MAX * (1 - 1 / (magnitude / (RUBBER_MAX * RUBBER_SOFTNESS) + 1))
}

/* ------------------------------------------------------------------------- */
/* Table pan                                                                  */
/* ------------------------------------------------------------------------- */

/**
 * Grab a wide table and throw it sideways.
 *
 * Delegated from the document rather than bound per scroller, for the reason
 * everything in this panel is delegated: a table re-renders itself every 30
 * seconds and any node captured at boot is a node Livewire may have replaced by
 * the time the operator reaches for it. One Observer covers every table on the
 * page, including the ones inside modals and the ones that do not exist yet.
 */
function tablePan({ gsap, ScrollTrigger, MOTION, clamp, scope, owned, leaned, settleCard, extras }) {
    /** The gesture in progress, or null. */
    let pan = null

    /** The inertial tween carrying a released throw, or null. */
    let glide = null

    /** Writes the current lean. Rebuilt per card, because quickSetter binds to
     *  one element. */
    let lean = null

    const killGlide = () => {
        if (glide) {
            glide.kill()
            glide = null
        }
    }

    // Any deliberate input ends a glide. A page that keeps sliding after the
    // operator has started doing something else is a page arguing with them.
    scope.on(document, 'wheel', killGlide, { passive: true, capture: true })
    scope.on(document, 'pointerdown', killGlide, { passive: true, capture: true })
    scope.on(document, 'keydown', killGlide, { capture: true })
    scope.on(window, 'blur', killGlide)
    scope.add(killGlide)

    /**
     * Swallow exactly one click, and only if one arrives promptly.
     *
     * A row in this panel can be clickable, so the pointerup that ends a throw
     * would otherwise open whatever record happened to be under the cursor when
     * the operator let go. Registered in capture so it beats Livewire's own
     * handler, removed as soon as it fires, and removed by a timer if the
     * release produced no click at all.
     *
     * One slot, not one per gesture: the teardown hook is registered once here
     * rather than inside the closure, or a long session would accumulate a dead
     * disposer for every pan the operator ever made.
     */
    let dropSwallow = null

    scope.add(() => {
        if (dropSwallow) {
            dropSwallow()
        }
    })

    const swallowClick = () => {
        if (dropSwallow) {
            dropSwallow()
        }

        let timer = 0

        const drop = () => {
            dropSwallow = null
            window.clearTimeout(timer)
            document.removeEventListener('click', stop, true)
        }

        function stop(event) {
            event.stopPropagation()
            event.preventDefault()
            drop()
        }

        document.addEventListener('click', stop, true)
        timer = window.setTimeout(drop, 140)
        dropSwallow = drop
    }

    /** Release the lean and let it spring home. */
    const unlean = (card) => {
        if (!card) {
            return
        }

        owned(() =>
            gsap.to(card, {
                x: 0,
                duration: MOTION.base,
                ease: MOTION.spring,
                overwrite: 'auto',
                clearProps: 'transform,willChange',
                onComplete: () => settleCard(card),
            }),
        )

        // The tween above is the normal path. This is what covers it being
        // killed by a navigation, a second gesture or a context revert.
        owned(() => gsap.delayedCall(MOTION.base + 0.5, () => settleCard(card)))
    }

    /**
     * Throw the scroller. `velocity` is the pointer's, in px/s; content moves
     * against the pointer, hence the sign flip.
     */
    const fling = (state, velocity) => {
        const speed = clamp(-6000, 6000, velocity || 0)

        if (Math.abs(speed) < FLING_MIN_VELOCITY) {
            return
        }

        const from = state.scroller.scrollLeft
        const reach = clamp(-FLING_MAX, FLING_MAX, -speed * FLING_REACH)
        const to = clamp(0, state.max, from + reach)
        const travel = Math.abs(to - from)

        // The throw was aimed past an end. The distance it did not get to cover
        // is what the card leans by -- the fling arriving at a wall.
        const spare = Math.abs(from + reach - to)

        if (travel < 4) {
            if (spare > 40 && extras.on) {
                bumpCard(
                    { gsap, MOTION, owned, leaned, settleCard, clamp },
                    state.card,
                    reach > 0 ? -1 : 1,
                    spare * 0.06,
                )
            }

            return
        }

        const duration = clamp(0.25, GLIDE_MAX, travel / 900 + 0.25)
        const proxy = { value: from }
        const scroller = state.scroller

        glide = owned(() =>
            gsap.to(proxy, {
                value: to,
                duration,
                // power3.out is the shape a thrown object actually decays with:
                // most of the distance early, a long quiet tail. expo.out, the
                // panel's house ease, stops so abruptly here that the table
                // reads as having hit something.
                ease: 'power3.out',
                onUpdate() {
                    if (!scroller.isConnected) {
                        killGlide()

                        return
                    }

                    scroller.scrollLeft = proxy.value
                },
                onComplete() {
                    glide = null

                    if (spare > 40 && extras.on) {
                        bumpCard(
                            { gsap, MOTION, owned, leaned, settleCard, clamp },
                            state.card,
                            reach > 0 ? -1 : 1,
                            spare * 0.05,
                        )
                    }
                },
            }),
        )
    }

    const finish = (self) => {
        const state = pan
        pan = null

        if (!state || !state.active) {
            return
        }

        state.scroller.removeAttribute(PAN_ATTR)

        // Unconditional: even a pan that never reached an edge was given a
        // `will-change` on activation, and that is a promise to the compositor
        // that has to be taken back.
        unlean(state.card)

        lean = null

        if (state.moved) {
            swallowClick()
        }

        fling(state, self ? self.velocityX : 0)
    }

    const observer = Observer.create({
        target: document,
        type: 'pointer',
        // Passive listeners, no cancelled events, no delayed clicks. The whole
        // module's safety story starts here.
        preventDefault: false,
        dragMinimum: 4,
        ignoreCheck(event, isPointerOrTouch) {
            if (!isPointerOrTouch) {
                return true
            }

            // Touch and pen keep the compositor's own scrolling, which already
            // has momentum and a rubber band and does not stall behind
            // JavaScript. On a hybrid machine GSAP listens to touch events even
            // when asked for pointers, so this is also what keeps the pan off
            // touchscreen laptops entirely.
            const type = event.pointerType

            if (type === 'touch' || type === 'pen') {
                return true
            }

            return typeof event.type === 'string' && event.type.indexOf('touch') === 0
        },
        onPress(self) {
            pan = null
            killGlide()

            const event = self.event
            const target = event && event.target

            if (!target || typeof target.closest !== 'function') {
                return
            }

            if (target.closest(NO_DRAG)) {
                return
            }

            const scroller = target.closest('.fi-ta-content-ctn')

            if (!scroller) {
                return
            }

            // Reordering already owns the drag on these rows, and it drags them
            // vertically. Two gestures on one press is one too many.
            if (scroller.querySelector('.fi-ta-table-reordering')) {
                return
            }

            const max = overflowOf(scroller)

            if (max < PAN_MIN_OVERFLOW) {
                return
            }

            pan = {
                scroller,
                card: scroller.closest('.fi-ta-ctn'),
                max,
                from: scroller.scrollLeft,
                active: false,
                rejected: false,
                moved: false,
            }
        },
        onDrag(self) {
            if (!pan || pan.rejected) {
                return
            }

            const dx = self.x - self.startX
            const dy = self.y - self.startY

            if (!pan.active) {
                // Committed to being a scroll. Nothing it does later can make
                // it a pan.
                if (Math.abs(dy) > PAN_GIVE_UP && Math.abs(dy) > Math.abs(dx)) {
                    pan.rejected = true

                    return
                }

                if (Math.abs(dx) < PAN_COMMIT || Math.abs(dx) < Math.abs(dy) * AXIS_BIAS) {
                    return
                }

                // The browser's verdict, not ours: if a selection has already
                // grown under this drag then the operator is copying a value out
                // of a row, and taking the gesture would be taking that away.
                const selection = window.getSelection ? window.getSelection() : null

                if (selection && !selection.isCollapsed) {
                    pan.rejected = true

                    return
                }

                pan.active = true
                pan.scroller.setAttribute(PAN_ATTR, '')

                if (pan.card) {
                    leaned.add(pan.card)
                    // A spring-home from the previous gesture would keep writing
                    // `x` behind the quickSetter below, and the card would fight
                    // the hand that is holding it.
                    gsap.killTweensOf(pan.card, 'x')
                    gsap.set(pan.card, { willChange: 'transform' })
                    lean = gsap.quickSetter(pan.card, 'x', 'px')
                }
            }

            if (!pan.scroller.isConnected) {
                pan = null

                return
            }

            pan.moved = true

            const wanted = pan.from - dx
            const reached = clamp(0, pan.max, wanted)

            pan.scroller.scrollLeft = reached

            if (!lean) {
                return
            }

            // Whatever the drag asked for beyond the ends of the table, damped,
            // shown as the card leaning that way. Written every frame, including
            // the zero: coming back off an edge has to unwind the lean as
            // smoothly as reaching it built one.
            lean(-rubber(wanted - reached))
        },
        onRelease: finish,
    })

    scope.add(() => observer.kill())

    // A press that never became a pan still left state behind.
    scope.add(() => {
        if (pan && pan.active) {
            pan.scroller.removeAttribute(PAN_ATTR)
        }

        pan = null
        lean = null
    })

    if (extras.on) {
        panHint({ gsap, ScrollTrigger, MOTION, owned, leaned, settleCard })
    }
}

/**
 * One lateral nudge, the first time a table that scrolls sideways comes into
 * view, so the operator finds out it does. Six pixels on the card, once, never
 * replayed -- the content itself does not move relative to the card, so no
 * value is ever mid-flight while being read.
 *
 * The card, not the scroller: a transform on the scroller would extend its own
 * scrollable width and make the horizontal scrollbar twitch, and at the far end
 * the browser would clamp scrollLeft to compensate and swallow the effect
 * entirely.
 */
function panHint({ gsap, ScrollTrigger, MOTION, owned, leaned, settleCard }) {
    if (!ScrollTrigger) {
        return
    }

    const cards = gsap.utils
        .toArray('.fi-main .fi-ta-ctn')
        .filter((card) => {
            const scroller = card.querySelector('.fi-ta-content-ctn')

            return scroller && overflowOf(scroller) >= PAN_MIN_OVERFLOW * 3
        })
        .slice(0, 6)

    cards.forEach((card) => {
        ScrollTrigger.create({
            trigger: card,
            start: 'top 88%',
            once: true,
            onEnter: () => {
                // Late enough that the scroll module's reveal of this same card
                // has landed and cleared its own transform. Two tweens writing
                // the card's transform at once is exactly the fight this file
                // exists to avoid.
                owned(() =>
                    gsap.delayedCall(0.85, () => {
                        if (!card.isConnected || gsap.isTweening(card) || modalIsOpen()) {
                            return
                        }

                        leaned.add(card)

                        owned(() =>
                            gsap
                                .timeline({ onComplete: () => settleCard(card) })
                                .set(card, { willChange: 'transform' })
                                .to(card, { x: -6, duration: 0.22, ease: MOTION.easeSoft })
                                .to(card, {
                                    x: 0,
                                    duration: 0.7,
                                    ease: MOTION.spring,
                                    clearProps: 'transform,willChange',
                                }),
                        )
                    }),
                )
            },
        })
    })
}

/**
 * A short lean and spring on a card, used wherever a gesture runs out of table.
 * Shared by the fling and by the wheel, so both ends of a table answer the same
 * way whichever input reached them.
 */
function bumpCard({ gsap, MOTION, owned, leaned, settleCard, clamp }, card, direction, force) {
    if (!card || !card.isConnected) {
        return
    }

    leaned.add(card)

    const distance = direction * clamp(4, 14, force)

    owned(() =>
        gsap
            .timeline({ onComplete: () => settleCard(card) })
            .set(card, { willChange: 'transform' })
            // 'auto', not true: this has to take `x` off the spring-home tween a
            // release just started, and must not touch the reveal module's
            // opacity or y if one is somehow still in flight.
            .to(card, { x: distance, duration: 0.14, ease: 'power2.out', overwrite: 'auto' })
            .to(card, {
                x: 0,
                duration: 0.55,
                ease: MOTION.spring,
                clearProps: 'transform,willChange',
            }),
    )

    owned(() => gsap.delayedCall(1.1, () => settleCard(card)))
}

/* ------------------------------------------------------------------------- */
/* Wheel physics: edge bump, and the pull affordance                          */
/* ------------------------------------------------------------------------- */

/**
 * One wheel Observer, two jobs, because both are questions about what the
 * wheel could not do.
 *
 *   - horizontally: the table under the cursor has no more to give, so the card
 *     leans;
 *   - vertically: the document is already at the top, so the wheel has nothing
 *     to scroll and the delta is genuinely spare -- which is the only reason a
 *     pull affordance can exist here without hijacking anything.
 */
function wheelPhysics({ gsap, MOTION, clamp, scope, owned, leaned, settleCard, extras }) {
    const bumpApi = { gsap, MOTION, owned, leaned, settleCard, clamp }

    let bumpedAt = 0

    /* Measuring a scroller means reading `scrollWidth`, which forces layout.
       Scrolling a wide table sideways with a trackpad fires a wheel event every
       frame, so the measurement is rate-limited well below the frame rate --
       still far more often than the bump's own cooldown allows it to matter. */
    let probedAt = 0

    /* --- The pull ------------------------------------------------------- */

    /* Only where a refresh means something. A page of widgets is the dashboard;
       a form page has nothing to re-read and a great deal to disturb. */
    const refreshable = Boolean(document.querySelector('.fi-wi-widget'))

    let chip = null
    let setY = null
    let setSpin = null
    let pulled = 0
    let firedAt = 0
    let retracting = false

    /* Set once per pull: the wheel landed inside a scroller of its own that
       still has somewhere to go, so the delta was never spare. */
    let blocked = false

    const buildChip = () => {
        if (chip || !refreshable) {
            return chip
        }

        const node = document.createElement('div')
        node.className = 'vf-pull'
        node.setAttribute(OWNED_ATTR, 'pull')
        // Decorative and non-interactive: it holds no content, takes no focus
        // and cannot be reached by a pointer, so it is invisible to assistive
        // technology by design rather than by omission.
        node.setAttribute('aria-hidden', 'true')
        node.innerHTML = CHIP_HTML

        document.body.appendChild(node)
        scope.add(() => {
            node.remove()
            chip = null
            setY = null
            setSpin = null
        })

        chip = node

        const icon = node.firstElementChild

        // Establishes GSAP's transform cache for the node before the quickSetter
        // below starts writing into it.
        gsap.set(node, { y: -64, opacity: 0 })

        setY = gsap.quickSetter(node, 'y', 'px')
        setSpin = icon ? gsap.quickSetter(icon, 'rotation', 'deg') : null

        return chip
    }

    const retract = () => {
        if (!chip || retracting) {
            return
        }

        retracting = true
        pulled = 0

        owned(() =>
            gsap.to(chip, {
                y: -64,
                opacity: 0,
                duration: MOTION.base,
                ease: MOTION.easeSoft,
                overwrite: 'auto',
                onComplete: () => {
                    retracting = false
                },
            }),
        )
    }

    /**
     * Re-read every widget on the page through Livewire's own public API. Not
     * the whole page: `$refresh` on a form component would commit whatever is
     * half-typed in it, and a poll is exactly what a widget already expects.
     *
     * Returns how many it reached, because an affordance that plays its
     * completion animation without having refreshed anything is a lie.
     */
    const refreshWidgets = () => {
        const live = window.Livewire

        if (!live || typeof live.all !== 'function') {
            return 0
        }

        let count = 0

        try {
            live.all().forEach((component) => {
                const element = component && component.el

                if (!element || !element.isConnected || typeof element.closest !== 'function') {
                    return
                }

                if (!element.closest('.fi-wi-widget')) {
                    return
                }

                const wire = component.$wire

                if (!wire || typeof wire.$refresh !== 'function') {
                    return
                }

                wire.$refresh()
                count += 1
            })
        } catch (error) {
            // Livewire's internals are not a contract. A refresh that cannot be
            // made is a retract, not an exception.
            return count
        }

        return count
    }

    const fire = () => {
        firedAt = performance.now()
        pulled = 0

        if (!refreshWidgets()) {
            retract()

            return
        }

        const icon = chip.firstElementChild

        retracting = true

        owned(() => {
            const tl = gsap.timeline({
                onComplete: () => {
                    retracting = false
                },
            })

            tl.to(chip, { y: 30, duration: 0.18, ease: MOTION.easeSoft, overwrite: 'auto' }, 0)

            if (icon) {
                tl.to(icon, { rotation: '+=540', duration: 0.95, ease: 'power2.inOut' }, 0)
            }

            tl.to(chip, { y: -64, opacity: 0, duration: 0.42, ease: MOTION.ease }, 0.66)

            return tl
        })
    }

    /**
     * True when the wheel landed inside something that still has room to scroll
     * up. A table with its own scrollbar is not the document, and consuming its
     * wheel would be the hijack this module refuses to perform.
     */
    const overNestedScroller = (node) => {
        let element = node
        let hops = 0

        while (element && element !== document.body && hops < 20) {
            if (element.scrollTop > 0) {
                return true
            }

            element = element.parentElement
            hops += 1
        }

        return false
    }

    const pull = (self) => {
        if (!refreshable || !extras.on) {
            return
        }

        // Everything below is only reachable because the browser had nothing to
        // do with this delta. The moment any of it stops being true, we stop.
        if ((window.scrollY || document.documentElement.scrollTop || 0) > 0) {
            retract()

            return
        }

        if (modalIsOpen() || retracting) {
            return
        }

        if (performance.now() - firedAt < PULL_COOLDOWN * 1000) {
            return
        }

        // Walking the ancestors reads `scrollTop`, which forces layout, so it is
        // asked once at the start of a pull rather than on every frame of one.
        // `pulled` returns to zero on every exit path, which is what re-arms it.
        if (pulled === 0) {
            const target = self.event && self.event.target

            blocked = Boolean(target && overNestedScroller(target))
        }

        if (blocked) {
            return
        }

        if (!buildChip()) {
            return
        }

        // A fast flick into the top of the page arrives with more behind it than
        // a slow scroll of the same distance -- that difference is the only
        // place scroll velocity is allowed to change anything in this module.
        const momentum = clamp(1, 1.6, Math.abs(self.velocityY) / 2400 + 1)

        pulled = Math.min(PULL_MAX, pulled + Math.abs(self.deltaY) * PULL_RESISTANCE * momentum)

        const eased = rubberPull(pulled)

        setY(eased - 64)
        // Written straight to the style rather than through a quickSetter: the
        // retract tween below owns `opacity` too, and the two would be writing
        // through different paths into the same property.
        chip.style.opacity = clamp(0, 1, pulled / 26).toFixed(3)

        if (setSpin) {
            setSpin((pulled / PULL_TRIGGER) * 200)
        }

        if (pulled >= PULL_TRIGGER) {
            fire()
        }
    }

    /* --- The bump ------------------------------------------------------- */

    const bump = (self) => {
        if (!extras.on || Math.abs(self.deltaX) < BUMP_MIN_DELTA) {
            return
        }

        const now = performance.now()

        if (now - bumpedAt < BUMP_COOLDOWN * 1000 || now - probedAt < 120) {
            return
        }

        probedAt = now

        const target = self.event && self.event.target

        if (!target || typeof target.closest !== 'function') {
            return
        }

        const scroller = target.closest('.fi-ta-content-ctn')

        if (!scroller) {
            return
        }

        const max = overflowOf(scroller)

        if (max < PAN_MIN_OVERFLOW) {
            return
        }

        const left = scroller.scrollLeft
        const atStart = left <= 1 && self.deltaX < 0
        const atEnd = left >= max - 1 && self.deltaX > 0

        if (!atStart && !atEnd) {
            return
        }

        bumpedAt = now

        bumpCard(
            bumpApi,
            scroller.closest('.fi-ta-ctn'),
            atStart ? 1 : -1,
            Math.abs(self.deltaX) * 0.35,
        )
    }

    const observer = Observer.create({
        target: document,
        type: 'wheel',
        preventDefault: false,
        tolerance: 2,
        onStopDelay: 0.22,
        onWheel(self) {
            // Whichever axis the operator is actually pushing on. A trackpad
            // reports a little of both on almost every gesture.
            if (Math.abs(self.deltaX) > Math.abs(self.deltaY)) {
                bump(self)

                return
            }

            if (self.deltaY < 0) {
                // Wheel and trackpad overscroll does NOT arm the refresh.
                // Pull-to-refresh is a touch idiom: on touch the operator has
                // deliberately dragged the page down past its top, while on a
                // desktop a trackpad's momentum keeps delivering upward deltas
                // after the page has already stopped at the top, so an ordinary
                // flick reached the threshold and silently refetched the
                // dashboard. The horizontal bump above is unaffected -- that one
                // only mirrors a scroll the operator really made.
                if (!coarsePointer()) {
                    return
                }

                pull(self)

                return
            }

            // Pushing back down cancels a pull immediately -- the operator has
            // changed their mind and the page is about to move.
            if (pulled > 0) {
                retract()
            }
        },
        onStop() {
            if (pulled > 0) {
                retract()
            }
        },
    })

    scope.add(() => observer.kill())
}

/** The pull's own resistance curve: generous at first, asymptotic after. */
function rubberPull(distance) {
    return PULL_MAX * (1 - 1 / (distance / (PULL_MAX * 0.55) + 1))
}

/* ------------------------------------------------------------------------- */
/* Toast swipe                                                                */
/* ------------------------------------------------------------------------- */

/**
 * Throw a toast away.
 *
 * Delegated at the document because toasts are created by Livewire on demand
 * and nothing that exists at boot is one.
 *
 * The dismissal is not ours. Past the threshold the card is thrown clear and
 * then Filament's own close button is clicked, so the Alpine component runs its
 * real teardown -- the leave transition, the `notificationClosed` event, the
 * Livewire round trip that removes the record. This module supplies the
 * gesture; Filament keeps owning what the gesture means. It is also what makes
 * the overlays module's exit choreography compose instead of fight: by the time
 * it sees the leave class the card is already at opacity 0 and off screen, so
 * its own exit plays where nobody can see it.
 */
function toastSwipe({ gsap, MOTION, clamp, scope, owned }) {
    let swipe = null
    let move = null

    /** Put a card back exactly as it was. Called on every path that is not a
     *  dismissal, and by the failsafe below. */
    const restore = (toast) => {
        if (!toast || !toast.isConnected) {
            return
        }

        toast.removeAttribute(SWIPE_ATTR)

        owned(() =>
            gsap.to(toast, {
                x: 0,
                opacity: 1,
                duration: MOTION.base,
                ease: MOTION.spring,
                overwrite: 'auto',
                clearProps: 'transform,opacity,willChange',
            }),
        )
    }

    const isLeaving = (toast) =>
        toast.classList.contains('fi-transition-leave-start') ||
        toast.classList.contains('fi-transition-leave-end')

    /**
     * Ask Filament to close this notification. The button is the honest route --
     * it is the same control a keyboard user would press. The window event is
     * the fallback for a notification rendered without one; `wire:key` is
     * `{id}.notifications.{id}`, which is the only place the id exists in the
     * DOM.
     */
    const close = (toast) => {
        const button = toast.querySelector('.fi-no-notification-close-btn')

        if (button) {
            try {
                button.click()

                return true
            } catch (error) {
                return false
            }
        }

        const key = toast.getAttribute('wire:key') || ''
        const id = key.split('.notifications.')[0]

        if (!id) {
            return false
        }

        window.dispatchEvent(new CustomEvent('close-notification', { detail: { id } }))

        return true
    }

    const finish = (self) => {
        const state = swipe
        swipe = null
        move = null

        if (!state || !state.active) {
            return
        }

        const toast = state.toast

        if (!toast.isConnected || isLeaving(toast)) {
            return
        }

        const distance = state.dx
        const threshold = Math.max(SWIPE_FLOOR, state.width * SWIPE_SHARE)
        const velocity = self ? self.velocityX : 0

        // Far enough, or thrown hard enough that where it currently is stopped
        // being the question. The direction test is what stops a flick that
        // ended by snapping back toward centre from counting as a throw.
        const thrown =
            Math.abs(distance) > threshold ||
            (Math.abs(velocity) > SWIPE_VELOCITY &&
                Math.abs(distance) > SWIPE_VELOCITY_FLOOR &&
                velocity > 0 === distance > 0)

        if (!thrown) {
            restore(toast)

            return
        }

        const direction = distance < 0 ? -1 : 1

        owned(() =>
            gsap.to(toast, {
                x: direction * (state.width + 48),
                opacity: 0,
                duration: 0.24,
                ease: 'power2.in',
                overwrite: 'auto',
                onComplete: () => {
                    if (!toast.isConnected || !close(toast)) {
                        restore(toast)
                    }
                },
            }),
        )

        // Rule two of this panel, made unconditional: if Filament never removed
        // the card -- a dropped Livewire round trip, a component that was
        // already gone -- it comes back rather than sitting there invisible.
        owned(() =>
            gsap.delayedCall(1.6, () => {
                if (toast.isConnected && !isLeaving(toast)) {
                    restore(toast)
                }
            }),
        )
    }

    const observer = Observer.create({
        target: document,
        // Both, unlike the table pan: a toast is small, fixed and has nothing
        // underneath it worth scrolling, and `touch-action: pan-y` in the
        // injected stylesheet already gave the vertical axis back to the
        // browser -- so taking the horizontal one costs nothing.
        type: 'touch,pointer',
        preventDefault: false,
        dragMinimum: 4,
        ignoreCheck(event, isPointerOrTouch) {
            return !isPointerOrTouch
        },
        onPress(self) {
            swipe = null
            move = null

            const target = self.event && self.event.target

            if (!target || typeof target.closest !== 'function') {
                return
            }

            // A control inside the toast owns its own press -- the close button,
            // and any action button the notification carries.
            if (target.closest('button, a[href], input, select, textarea, [role="button"]')) {
                return
            }

            const toast = target.closest('.fi-no-notification')

            if (!toast || toast.classList.contains('fi-inline')) {
                return
            }

            if (toast.hasAttribute(SWIPE_ATTR) || isLeaving(toast)) {
                return
            }

            swipe = {
                toast,
                width: toast.offsetWidth || 320,
                active: false,
                rejected: false,
                dx: 0,
            }
        },
        onDrag(self) {
            if (!swipe || swipe.rejected) {
                return
            }

            const dx = self.x - self.startX
            const dy = self.y - self.startY

            if (!swipe.active) {
                if (Math.abs(dy) > PAN_GIVE_UP && Math.abs(dy) > Math.abs(dx)) {
                    swipe.rejected = true

                    return
                }

                if (Math.abs(dx) < PAN_COMMIT || Math.abs(dx) < Math.abs(dy) * AXIS_BIAS) {
                    return
                }

                if (!swipe.toast.isConnected || isLeaving(swipe.toast)) {
                    swipe = null

                    return
                }

                swipe.active = true
                swipe.toast.setAttribute(SWIPE_ATTR, '')

                // The overlays module may still be sliding this card in. Only
                // its placement tweens are killed -- the countdown bar runs on a
                // custom property and is none of our business -- and the card is
                // then put at rest, because a tween killed halfway leaves its
                // own half-finished offset and scale behind.
                gsap.killTweensOf(swipe.toast, 'x,y,scale')
                gsap.set(swipe.toast, { y: 0, scale: 1, willChange: 'transform, opacity' })

                move = gsap.quickSetter(swipe.toast, 'x', 'px')
            }

            swipe.dx = dx
            move(dx)

            // Fades toward, never to, invisible. A card the operator has not yet
            // committed to throwing has to stay readable, because they may be
            // reading it -- that is why they grabbed it.
            swipe.toast.style.opacity = clamp(
                SWIPE_FADE_FLOOR,
                1,
                1 - Math.abs(dx) / (swipe.width * 1.35),
            ).toFixed(3)
        },
        onRelease: finish,
    })

    scope.add(() => observer.kill())

    // A card left mid-gesture by a teardown is put back by hand: quickSetter and
    // a direct style write are both invisible to gsap.context().
    scope.add(() => {
        document.querySelectorAll(`.fi-no-notification[${SWIPE_ATTR}]`).forEach((toast) => {
            toast.removeAttribute(SWIPE_ATTR)
            toast.style.removeProperty('opacity')
            gsap.set(toast, { clearProps: 'transform,willChange' })
        })

        swipe = null
        move = null
    })
}
