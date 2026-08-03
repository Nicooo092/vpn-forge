/*
 * Perspective and depth
 * ---------------------
 * The panel's stat cards are flat rectangles of text. interactions.js already
 * leans one toward the cursor; this module is what turns that lean into a
 * dimension -- a shared vanishing point for the whole row, the card's own
 * contents separating into planes under the pointer, and every other card in the
 * row falling back out of focus while you look at one.
 *
 * TERRITORY. interactions.js owns the stat CARD: its rotation, its lift, its
 * spotlight. This module never writes a transform to a card and never touches a
 * property interactions.js drives. It owns three things interactions.js does not
 * touch at all:
 *
 *   1. `transform-origin` on the cards -- which is where a `perspective()`
 *      transform puts its vanishing point.
 *   2. the card's CHILD planes (icon, label, value, description).
 *   3. `.fi-wi-stats-overview-stat-content` and the sparkline -- scale and
 *      opacity, for the depth of field.
 *
 * So the two compose instead of fighting: interactions.js supplies the rotation,
 * this file decides what that rotation is rotating *about* and what moves inside
 * it. Extending that surface beat claiming a new one, because the only other
 * cards in this panel are `.fi-section`s -- and motion.css has already ruled, in
 * writing, that sections do not move, for the reason in the next paragraph.
 *
 * WHY THERE IS NO `perspective` ANYWHERE IN HERE. The obvious build is a
 * perspective container on the grid. It is also unshippable: `perspective` makes
 * an element the containing block for every `position: fixed` descendant,
 * exactly as `transform` does, and Filament renders action modals inline inside
 * the section or table that owns them. One property on a grid wrapper would
 * re-anchor every modal beneath it to a card. The shared vanishing point is
 * therefore built out of `transform-origin` instead -- a property with no such
 * side effect -- by pointing each card's origin at the middle of its own group.
 * A card on the left of the row then rotates about a point off to its right,
 * which is what "these cards all face one place" means geometrically. Every
 * element that does receive a transform here is a text container that cannot
 * hold a control, and a group containing a modal is refused outright.
 *
 * COST. Nothing runs unless a pointer is over a stat card. No ticker, no
 * ScrollTrigger, no observer, no canvas, no injected stylesheet, nothing
 * continuous. Per frame the work is one cached rect read and four `quickTo`
 * calls. Gated to the governor's 'high' tier and to `pointer: fine`, and it
 * stands itself down if the governor downgrades the device mid-session.
 *
 * SAFETY. The depth of field dims by 10% and scales by 2%: a receded card is
 * still a card an operator can read a number off, which is the whole reason it
 * is a recede and not a blur. Everything is released on pointer-leave, on focus
 * landing in a receded card, on window blur, on a hidden tab, on a Livewire
 * morph -- and by a watchdog that asks the browser itself whether the pointer is
 * still there, because a polling widget can have its card replaced under the
 * cursor without any pointerout ever being fired.
 */

/* Marks an element this module has written an inline style to, and says which
   KIND of style, because the two must be undone differently: a card gets only
   its vanishing point back, and must not have `transform` stripped from it --
   that one belongs to interactions.js and would be torn out mid-rotation. */
const OWNED_ATTR = 'data-vf-depth'
const AS_CARD = 'card'
const AS_PLANE = 'plane'

/**
 * The card's own planes, front to back, and how far each travels relative to the
 * nearest one.
 *
 * Under a shared vanishing point a plane's apparent travel is proportional to
 * its distance from the glass, so these ratios ARE the depth. The value is the
 * reason the card exists and sits nearest; the icon is a glyph with nothing
 * depending on where it lands, so it can float well forward without the card
 * looking broken; label and description are context and stay back.
 *
 * The sparkline (`.fi-wi-stats-overview-stat-chart`) is deliberately not a
 * plane. It is absolutely positioned against the card's bottom edge with its own
 * `overflow: hidden`, and the card itself is not clipped -- so a two-pixel shift
 * would push it out through the card's rounded corner.
 */
const LAYERS = [
    { selector: '.fi-wi-stats-overview-stat-value', depth: 1 },
    { selector: '.fi-wi-stats-overview-stat-label-ctn > .fi-icon', depth: 0.7 },
    { selector: '.fi-wi-stats-overview-stat-description', depth: 0.45 },
    { selector: '.fi-wi-stats-overview-stat-label', depth: 0.28 },
]

/* Peak travel of the nearest plane, in px, at the corner of the card. Read
   against interactions.js's 1.6deg card tilt: much more than this and the planes
   read as sliding independently rather than as one object seen from an angle. */
const LAYER_TRAVEL = 5

/* A near plane is light and answers immediately; a far one is heavy and lags.
   Same amplitude ratio, different time constant -- which is most of what makes
   the separation read as depth rather than as a stagger. */
const FOLLOW_FAST = 0.42
const FOLLOW_SPREAD = 0.22

/* Depth of field. The pointed-at card comes forward, the rest fall back. Small
   on purpose: at 0.9 the contrast of Filament's gray-500 label is essentially
   unchanged, so the recede is carried by the scale, which costs nothing to read.
   This is an operations dashboard -- a card nobody is pointing at is still a
   card someone is comparing against. */
const NEAR_SCALE = 1.012
const FAR_SCALE = 0.978
const FAR_OPACITY = 0.9

/* How far outside its own box a card's vanishing point may sit, as a percentage
   of the card. Unclamped, the outer card of a wide row gets an origin 150% away
   and its 1.6deg rotation turns into a visible sideways slide. */
const ORIGIN_MIN = -25
const ORIGIN_MAX = 125

/* Groups larger than this are a list of readings, not a composition, and
   receding eleven cards to emphasise one is noise rather than depth. */
const MAX_GROUP = 12

/* How often the watchdog asks whether the pointer is really still there. Alive
   only while a card is actually engaged. */
const WATCH_SECONDS = 2.5

/**
 * Perspective, layer parallax and depth of field on the stat card grid.
 *
 * @param {{gsap: import('gsap').gsap, ScrollTrigger: object, MOTION: object}} api
 * @returns {null}
 */
export function depthLayer({ gsap, MOTION }) {
    if (typeof document === 'undefined' || !document.body) {
        return null
    }

    // Heal whatever the previous run left behind before building anything. The
    // runtime's context.revert() undoes our tweens, but the vanishing point is a
    // raw style assignment and is ours alone to remove.
    healAll()

    /*
     * The governor's verdict, read defensively -- this module has to work
     * whether perf.js ran, failed, or was deleted. `allow()` is preferred over
     * the bare tier because it also honours the operator's `off` override and a
     * reduced-motion preference that arrived after boot; the tier comparison is
     * the fallback for a contract that is not there.
     */
    const governor = window.__vfMotion ?? null
    const tier = governor?.tier ?? 'medium'

    const permitted =
        typeof governor?.allow === 'function' ? governor.allow('depth') : tier === 'high'

    // Below 'high' this is the first thing that should go: per-frame work in
    // service of an effect nobody came here for.
    if (!permitted) {
        return null
    }

    const clamp = gsap.utils.clamp
    const scope = createScope()

    // gsap.context() reverts tweens, not listeners. This nested context exists
    // only so the runtime's revert() reaches the disposer below.
    gsap.context(() => () => {
        release(false)
        scope.dispose()
    })

    /*
     * `gsap.context()` with no argument hands back the context we are currently
     * running inside -- the runtime's. Tweens created later, from a pointer
     * handler, would otherwise belong to no context at all: nothing would revert
     * them, and the inline transforms they left behind would survive the next
     * navigation frozen mid-flight.
     *
     * The result is fished out through a closure rather than returned, because
     * context.add() treats a returned FUNCTION as a cleanup callback and would
     * call it on revert -- and gsap.quickTo() returns a function.
     */
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

    /* --------------------------------------------------------------------- */
    /* State                                                                  */
    /* --------------------------------------------------------------------- */

    /** The card under the pointer, and the stats widget it belongs to. */
    let hotCard = null
    let hotGroup = null

    /** Cards currently pushed back, so they can be brought forward again without
     *  re-deriving the group from a DOM that may since have morphed. */
    let receded = []

    /** Per-card plane rigs. Keyed on the card, so a morph that replaces a card
     *  drops its entry for free -- but a morph can also replace a card's
     *  CHILDREN while keeping the card, so a rig is validated before reuse. */
    const rigs = new WeakMap()

    let watchdog = null

    /* --------------------------------------------------------------------- */
    /* Measurement                                                            */
    /* --------------------------------------------------------------------- */

    /*
     * Bounding boxes, cached per element and thrown away whenever anything could
     * have moved. Measuring on every pointermove is a forced layout per frame;
     * measuring once and trusting it forever puts the vanishing point somewhere
     * else on the page after a scroll.
     */
    const boxes = new WeakMap()
    let generation = 1

    const invalidate = () => {
        generation += 1
    }

    // Scroll does not bubble, but it does reach a capturing listener on window,
    // which is how the page and any nested scroller are both covered.
    scope.on(window, 'scroll', invalidate, { passive: true, capture: true })
    scope.on(window, 'resize', invalidate, { passive: true })

    const rectOf = (element) => {
        const hit = boxes.get(element)

        if (hit && hit.generation === generation) {
            return hit.rect
        }

        const rect = element.getBoundingClientRect()
        boxes.set(element, { rect, generation })

        return rect
    }

    /* --------------------------------------------------------------------- */
    /* Pieces of a card                                                       */
    /* --------------------------------------------------------------------- */

    /**
     * The parts of a card that recede: its content column, and its sparkline if
     * it has one. The card's own surface -- background, ring, shadow -- is left
     * alone, so a pushed-back card still looks like a card rather than a hole in
     * the page.
     */
    function shellOf(card) {
        return {
            content: card.querySelector('.fi-wi-stats-overview-stat-content'),
            chart: card.querySelector('.fi-wi-stats-overview-stat-chart'),
        }
    }

    /**
     * The quickTo pair for each of a card's planes, built on first hover.
     *
     * @param {Element} card
     * @returns {?Array<{el: Element, depth: number, x: Function, y: Function}>}
     */
    function rigFor(card) {
        const cached = rigs.get(card)

        // A poll can re-render a card's contents while leaving the card itself
        // in place, which would leave the rig driving detached nodes.
        if (cached && cached.length && cached[0].el.isConnected) {
            return cached
        }

        const planes = []

        LAYERS.forEach((layer) => {
            const el = card.querySelector(layer.selector)

            // Stats in this panel carry no icon today and often no description.
            // A missing plane is the normal case, not a failure.
            if (el) {
                planes.push({ el, depth: layer.depth })
            }
        })

        if (!planes.length) {
            rigs.delete(card)

            return null
        }

        const rig = planes.map((plane) => {
            const duration = FOLLOW_FAST + (1 - plane.depth) * FOLLOW_SPREAD

            return owned(() => ({
                el: plane.el,
                depth: plane.depth,
                x: gsap.quickTo(plane.el, 'x', { duration, ease: MOTION.easeSoft }),
                y: gsap.quickTo(plane.el, 'y', { duration, ease: MOTION.easeSoft }),
            }))
        })

        rigs.set(card, rig)

        return rig
    }

    /**
     * Hand a card's planes to GSAP and start them from rest.
     *
     * The `transition: none` matters: a CSS transition on a property GSAP writes
     * every frame re-eases each write, and the plane swims behind the pointer
     * instead of tracking it. Nothing in the stylesheet transitions these today;
     * this is so that stays true if the CSS layer grows.
     */
    function claimPlanes(card) {
        const rig = rigFor(card)

        if (!rig) {
            return null
        }

        const elements = rig.map((plane) => {
            plane.el.setAttribute(OWNED_ATTR, AS_PLANE)

            return plane.el
        })

        owned(() => gsap.set(elements, { transition: 'none', willChange: 'transform' }))

        return rig
    }

    /**
     * Send a card's planes back to rest, and strip the inline residue once they
     * have arrived -- unless the pointer has come back in the meantime.
     * `will-change` especially: left behind on every card the pointer has ever
     * crossed, a dashboard ends up holding compositor layers it does not need.
     */
    function sendPlanesHome(card) {
        const rig = card ? rigs.get(card) : null

        if (!rig) {
            return
        }

        rig.forEach((plane) => {
            plane.x(0)
            plane.y(0)
        })

        owned(() =>
            gsap.delayedCall(FOLLOW_FAST + FOLLOW_SPREAD + 0.15, () => {
                if (card !== hotCard) {
                    heal(rig.map((plane) => plane.el))
                }
            }),
        )
    }

    /* --------------------------------------------------------------------- */
    /* The shared vanishing point                                             */
    /* --------------------------------------------------------------------- */

    /**
     * Point every card in a group at the group's own centre.
     *
     * interactions.js rotates each card about `perspective(900px)`, whose
     * vanishing point is that card's `transform-origin`. Left at the default,
     * every card is its own little world and a row of six reads as six unrelated
     * wobbles. Moving each origin to the group's centre makes the row one
     * surface seen from one place.
     *
     * Written with a raw style assignment rather than gsap.set, because GSAP
     * treats transformOrigin as part of the transform block and re-renders
     * `transform` along with it -- which would both stomp interactions.js's live
     * rotation and put an inline transform, and therefore a containing block, on
     * cards that had neither.
     *
     * @param {Element[]} cards
     */
    function applyVanishingPoint(cards) {
        if (!hotGroup) {
            return
        }

        // Every read first, then every write: one layout pass for the group.
        const groupRect = rectOf(hotGroup)

        if (!groupRect.width || !groupRect.height) {
            return
        }

        const rects = cards.map((card) => rectOf(card))
        const vx = groupRect.left + groupRect.width / 2
        const vy = groupRect.top + groupRect.height / 2

        cards.forEach((card, index) => {
            const rect = rects[index]

            if (!rect.width || !rect.height) {
                return
            }

            const ox = clamp(ORIGIN_MIN, ORIGIN_MAX, ((vx - rect.left) / rect.width) * 100)
            const oy = clamp(ORIGIN_MIN, ORIGIN_MAX, ((vy - rect.top) / rect.height) * 100)

            card.setAttribute(OWNED_ATTR, AS_CARD)
            card.style.transformOrigin = `${ox.toFixed(1)}% ${oy.toFixed(1)}%`
        })
    }

    /* --------------------------------------------------------------------- */
    /* Depth of field                                                         */
    /* --------------------------------------------------------------------- */

    /**
     * Bring the pointed-at card forward and push its neighbours back.
     *
     * @param {Element[]} cards  every card in the group, the hot one included
     */
    function focusOn(cards) {
        receded = []

        cards.forEach((card) => {
            const near = card === hotCard
            const { content, chart } = shellOf(card)

            if (!content && !chart) {
                return
            }

            if (!near) {
                receded.push(card)
            }

            if (content) {
                content.setAttribute(OWNED_ATTR, AS_PLANE)

                owned(() =>
                    gsap.to(content, {
                        scale: near ? NEAR_SCALE : FAR_SCALE,
                        opacity: near ? 1 : FAR_OPACITY,
                        duration: MOTION.fast,
                        ease: MOTION.easeSoft,
                        overwrite: 'auto',
                    }),
                )
            }

            // The sparkline fades but never scales: it is pinned to the card's
            // bottom corners and clipped to their radius, so scaling it would
            // unstick it from the edge it is drawn against.
            if (chart) {
                chart.setAttribute(OWNED_ATTR, AS_PLANE)

                owned(() =>
                    gsap.to(chart, {
                        opacity: near ? 1 : FAR_OPACITY,
                        duration: MOTION.fast,
                        ease: MOTION.easeSoft,
                        overwrite: 'auto',
                    }),
                )
            }
        })
    }

    /**
     * Undo the depth of field on one card. Always ends by clearing its own
     * inline styles, so a card that a later run never touches again is left
     * exactly as the server rendered it.
     */
    function restoreCard(card) {
        if (!card.isConnected) {
            return
        }

        const { content, chart } = shellOf(card)
        const parts = []

        if (content) {
            parts.push(content)
        }

        if (chart) {
            parts.push(chart)
        }

        if (!parts.length) {
            return
        }

        owned(() =>
            gsap.to(parts, {
                scale: 1,
                opacity: 1,
                duration: MOTION.base * 0.7,
                ease: MOTION.easeSoft,
                overwrite: 'auto',
                clearProps: 'transform,opacity,willChange,transition',
                onComplete: () => {
                    if (card !== hotCard) {
                        parts.forEach((part) => part.removeAttribute(OWNED_ATTR))
                    }
                },
            }),
        )
    }

    /* --------------------------------------------------------------------- */
    /* Engage / release                                                       */
    /* --------------------------------------------------------------------- */

    /**
     * The cards that make up a card's group, or null when there is no group
     * worth composing.
     *
     * A group containing a modal is refused outright. Nothing in a stats widget
     * renders one today -- a Stat is label, value, description and sparkline --
     * but a modal is `position: fixed`, and this is the check that keeps rule
     * four true if a future Stat ever gains an action.
     */
    function groupFor(card) {
        const group = card.closest('.fi-wi-stats-overview')

        if (!group || group.querySelector('.fi-modal')) {
            return null
        }

        const cards = Array.from(group.querySelectorAll('.fi-wi-stats-overview-stat'))

        // One card has no neighbours to recede and no vanishing point to share.
        if (cards.length < 2 || cards.length > MAX_GROUP) {
            return null
        }

        return { group, cards }
    }

    function engage(card) {
        hotCard = card

        claimPlanes(card)

        const found = groupFor(card)

        if (found) {
            hotGroup = found.group

            applyVanishingPoint(found.cards)
            focusOn(found.cards)
        }

        // Armed even without a group: the planes alone are enough state to get
        // stranded if the pointer leaves without saying so.
        startWatch()
    }

    /**
     * Give everything back.
     *
     * @param {boolean} animate  false during teardown, on a hidden tab and after
     *                           a morph -- when a settle-back tween would either
     *                           outlive the context that is meant to own it or
     *                           play to nobody.
     */
    function release(animate) {
        const card = hotCard
        const group = hotGroup
        const dimmed = receded

        hotCard = null
        hotGroup = null
        receded = []

        stopWatch()

        if (!animate) {
            healAll()

            return
        }

        sendPlanesHome(card)

        if (card) {
            restoreCard(card)
        }

        dimmed.forEach(restoreCard)

        /*
         * The vanishing point is held a little longer than everything else.
         * interactions.js takes ~0.55s to rotate a card back to flat, and moving
         * the origin out from under a rotation that is still running makes the
         * card visibly slide on its way to rest.
         */
        if (group && group.isConnected) {
            const cards = Array.from(group.querySelectorAll('.fi-wi-stats-overview-stat'))

            owned(() =>
                gsap.delayedCall(0.75, () => {
                    if (hotGroup === group) {
                        return
                    }

                    cards.forEach((other) => {
                        if (other !== hotCard) {
                            heal([other])
                        }
                    })
                }),
            )
        }
    }

    /* --------------------------------------------------------------------- */
    /* Watchdog                                                               */
    /* --------------------------------------------------------------------- */

    /**
     * Rule one of this panel: nothing may be left in a changed state because an
     * event did not fire. `pointerout` is exactly such an event -- a stats
     * widget polls, Livewire morphs the card out from under the cursor, and
     * there is no longer an element for the browser to fire it on.
     *
     * So the browser is asked directly instead. `:hover` is maintained by the
     * engine and is the only authoritative answer to "is the pointer still
     * there". This exists only while a card is engaged, and stops the moment it
     * is not -- there is no timer running on an idle dashboard.
     */
    function startWatch() {
        if (watchdog) {
            return
        }

        const tick = () => {
            watchdog = null

            if (!hotCard) {
                return
            }

            if (!hotCard.isConnected || !isHovered(hotCard)) {
                release(true)

                return
            }

            watchdog = owned(() => gsap.delayedCall(WATCH_SECONDS, tick))
        }

        watchdog = owned(() => gsap.delayedCall(WATCH_SECONDS, tick))
    }

    function stopWatch() {
        if (watchdog) {
            watchdog.kill()
            watchdog = null
        }
    }

    /* --------------------------------------------------------------------- */
    /* Wiring                                                                 */
    /* --------------------------------------------------------------------- */

    /*
     * The governor can downgrade a device several seconds into a session, long
     * after the runtime has stopped re-running modules. Standing down is one-way
     * on purpose: re-arming on a recovery would mean this module flickering in
     * and out on exactly the machine that is already struggling. The next
     * navigation re-runs everything and gives the device a fair hearing.
     */
    scope.on(document, 'vf:motion-tier', (event) => {
        if (event.detail && event.detail.tier !== 'high') {
            release(false)
            scope.dispose()
        }
    })

    // No cursor to react to means every listener below would be dead weight
    // riding along on a phone. matchMedia also reverts the branch cleanly on a
    // device that gains or loses a mouse mid-session.
    const mm = gsap.matchMedia()
    scope.add(() => mm.revert())

    mm.add('(pointer: fine)', () => {
        const fine = createScope()

        fine.on(
            document,
            'pointerover',
            (event) => {
                // A pen hovers like a mouse; a finger does not hover at all, and
                // would leave one card lit and its neighbours pushed back for
                // the rest of the session.
                if (event.pointerType === 'touch' || !event.target || !event.target.closest) {
                    return
                }

                const card = event.target.closest('.fi-wi-stats-overview-stat')

                if (card === hotCard) {
                    return
                }

                /*
                 * Moving between two cards of the same row only changes which
                 * one is in front. The vanishing point is already correct for
                 * every card in that row, so rebuilding it would be a layout
                 * read for no change -- this is a re-focus, not a re-engage.
                 */
                const sameGroup =
                    card && hotGroup && hotGroup.isConnected && hotGroup.contains(card)

                if (sameGroup) {
                    const previous = hotCard

                    hotCard = card
                    claimPlanes(card)
                    sendPlanesHome(previous)
                    focusOn(Array.from(hotGroup.querySelectorAll('.fi-wi-stats-overview-stat')))

                    return
                }

                if (hotCard) {
                    release(true)
                }

                if (card) {
                    engage(card)
                }
            },
            { passive: true },
        )

        fine.on(
            document,
            'pointermove',
            (event) => {
                if (!hotCard || event.pointerType === 'touch') {
                    return
                }

                if (!hotCard.isConnected) {
                    release(false)

                    return
                }

                const rig = rigs.get(hotCard)

                if (!rig) {
                    return
                }

                const rect = rectOf(hotCard)

                if (!rect.width || !rect.height) {
                    return
                }

                /*
                 * Normalised to -1..1 from the card's centre. The planes travel
                 * WITH the pointer, in proportion to their depth: the card is
                 * leaning toward the cursor, and under a shared vanishing point
                 * a plane nearer the glass sweeps further across the card face
                 * than one behind it. That difference is the whole effect.
                 */
                const nx = clamp(-1, 1, ((event.clientX - rect.left) / rect.width - 0.5) * 2)
                const ny = clamp(-1, 1, ((event.clientY - rect.top) / rect.height - 0.5) * 2)

                rig.forEach((plane) => {
                    plane.x(nx * LAYER_TRAVEL * plane.depth)
                    plane.y(ny * LAYER_TRAVEL * plane.depth)
                })
            },
            { passive: true },
        )

        // Leaving the document produces no pointerover for anything else, so
        // without this nothing would ever be given back.
        fine.on(
            document,
            'pointerout',
            (event) => {
                if (!event.relatedTarget && hotCard) {
                    release(true)
                }
            },
            { passive: true },
        )

        return () => fine.dispose()
    })

    // A card that has just taken keyboard focus must not be one of the ones
    // being held back. Deliberately narrow: releasing on every focus change
    // would tear the effect down while the pointer is genuinely still on a card.
    scope.on(document, 'focusin', (event) => {
        const target = event.target

        if (!target || !receded.length) {
            return
        }

        if (receded.some((card) => card === target || card.contains(target))) {
            release(true)
        }
    })

    scope.on(window, 'blur', () => {
        if (hotCard) {
            release(true)
        }
    })

    // Nothing here is continuous, but a tab that goes away with a card engaged
    // would come back to a card still pushed back and a watchdog that slept
    // through the whole thing.
    scope.on(document, 'visibilitychange', () => {
        if (document.hidden && hotCard) {
            release(false)
        }
    })

    scope.on(document, 'livewire:navigating', () => release(false))

    /*
     * A stats widget polls. The morph re-creates cards, which invalidates every
     * measurement and can leave a rig driving detached nodes. If the pointer is
     * genuinely still on a live card, rebuild around it; otherwise hand
     * everything back and wait to be hovered again.
     */
    scope.on(document, 'livewire:update', () => {
        invalidate()

        if (!hotCard) {
            return
        }

        const card = hotCard
        const alive = card.isConnected && isHovered(card)

        release(false)

        if (alive) {
            engage(card)
        }
    })

    return null
}

/* ------------------------------------------------------------------------- */
/* Helpers                                                                    */
/* ------------------------------------------------------------------------- */

/**
 * A bag of teardown callbacks. Every listener in this file goes through one,
 * because gsap.context() reverts tweens and nothing else.
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
                    console.warn('[vpn-forge motion] depth cleanup failed', error)
                }
            }
        },
    }
}

/**
 * Is the pointer still over this element, according to the browser?
 *
 * Optimistic on failure: a `:hover` selector some engine refuses to match must
 * not be read as "the pointer left", which would tear the effect down every few
 * seconds for no reason.
 */
function isHovered(element) {
    try {
        return element.matches(':hover')
    } catch (error) {
        return true
    }
}

/**
 * Strip this module's inline styles from specific elements.
 *
 * Raw property removal rather than gsap.set(clearProps), so it is safe to call
 * while a context is mid-revert -- a tween created then would outlive the thing
 * meant to own it. A card only ever gives back its vanishing point: its
 * `transform` belongs to interactions.js, and tearing that out from under a
 * running rotation is exactly the kind of cross-module damage this file exists
 * to avoid.
 *
 * @param {Iterable<Element>} elements
 */
function heal(elements) {
    Array.prototype.forEach.call(elements, (element) => {
        if (!element || !element.style) {
            return
        }

        const role = element.getAttribute(OWNED_ATTR)

        element.removeAttribute(OWNED_ATTR)
        element.style.removeProperty('transform-origin')

        if (role === AS_CARD) {
            return
        }

        element.style.removeProperty('transform')
        element.style.removeProperty('opacity')
        element.style.removeProperty('will-change')
        element.style.removeProperty('transition')
    })
}

/** Every element this module has touched, anywhere on the page. */
function healAll() {
    heal(document.querySelectorAll(`[${OWNED_ATTR}]`))
}
