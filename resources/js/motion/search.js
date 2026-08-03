/**
 * Global search as a command palette.
 * -----------------------------------
 * Filament's global search is a plain input with a Livewire-rendered dropdown
 * (`.fi-global-search-ctn` -> `.fi-global-search-field` + a
 * `.fi-global-search-results-ctn` that only exists while `$results !== null`).
 * This module gives that surface the Spotlight treatment: the page recedes
 * behind a veil while the field lifts out of it, results cascade in as they
 * land, a rail travels between whichever result is highlighted, and the
 * no-results line is revealed rather than dropped in.
 *
 * ONE RULE OUTRANKS EVERY OTHER DECISION IN THIS FILE: this is a keyboard
 * surface. Nothing here may sit between a keystroke and its effect. Concretely:
 *
 *   - Not a single handler is bound to `keydown` or `input` on the field. The
 *     module learns that results changed by watching the DOM after Livewire has
 *     already painted them, never by intercepting the event that caused it.
 *   - Every reaction is coalesced into a requestAnimationFrame, so a burst of
 *     Livewire morphs costs one pass, not one per mutation.
 *   - Results are never queued behind an animation. A cascade that is still
 *     running when the next result set lands is killed outright and the rows it
 *     owned are handed straight back to the stylesheet -- readable immediately,
 *     mid-fade or not.
 *   - Arrow-key navigation is Filament's (`$focus.wrap().next()`), untouched.
 *     The rail *follows* focus via `focusin`; it never moves focus, never traps
 *     it, and never renders inside a focusable element.
 *
 * What is animated, and what is deliberately not:
 *
 *   - The result ROW (`li.fi-global-search-result`) is never a target. Filament
 *     renders result actions inline inside it (`.fi-global-search-result-actions`),
 *     and those actions render modals -- a live transform on an ancestor makes it
 *     the containing block for their `position: fixed` overlay and drops the
 *     modal into the dropdown. The `<a class="fi-global-search-result-link">` and
 *     the group header are animated instead: both are leaf content, neither
 *     contains a control that can open anything.
 *   - The results container does get a transform, but it already has one --
 *     Filament ships `transform: translateZ(0)` on it for a Safari stacking bug --
 *     so this changes nothing about containing blocks, and it is cleared the
 *     moment the tween ends regardless.
 *   - The veil and the rail are appended to `<body>`, not into the dropdown.
 *     Livewire re-morphs that subtree on every debounce tick and strips DOM it
 *     did not render; a rail living inside it would need re-asserting several
 *     times a second on the one surface that must never stutter.
 *
 * Readability failsafes (nothing may be left invisible):
 *   - Every reveal is a `fromTo` whose end state is the element's natural one,
 *     with `clearProps` handing it back to the stylesheet.
 *   - Each cascade also arms a wall-clock timer that force-clears the same
 *     properties, so a tween interrupted by a teardown, a morph or a thrown
 *     error still ends with readable results.
 *   - The veil is `pointer-events: none` and is dropped on blur, on Escape, on
 *     tab hide and on teardown. It can dim the page; it can never block it.
 *
 * @param {{gsap: import('gsap').gsap, ScrollTrigger: object, MOTION: object}} api
 */

import { Flip } from 'gsap/Flip'

const STYLE_ID = 'vf-search'

/* Every node this module appends carries this. A run sweeps the previous run's
   set away before creating its own -- gsap.context() reverts tweens, never DOM,
   and a crash mid-run must not leave a veil sitting over the panel. */
const NODE_FLAG = 'data-vf-search-node'

/* Carried by the field while it is focused; the injected stylesheet hangs the
   aura pseudo-element off it. Removed once the aura has faded back out. */
const FIELD_FLAG = 'data-vf-search'

/* Past this many rows a cascade stops reading as a cascade and starts reading as
   latency before results are legible. The container is `max-h-96` and rows are
   ~60px, so roughly six are on screen at once -- everything beyond the cap is
   below its scroll fold and is simply left alone. */
const ROW_CAP = { high: 14, medium: 10, low: 6 }

/* Deferred callbacks (frame gates, wall-clock failsafes) capture the boot they
   belong to. `livewire:navigated` re-runs the module, and a callback from the
   previous run writing over rows the current run owns would fight it. */
let generation = 0

const CSS = `
/* Registered so GSAP can read the current value back as a number; an
   unregistered custom property reads as an empty string and will not tween. */
@property --vf-search-aura { syntax: '<number>'; inherits: false; initial-value: 0; }

/* Filament's own primary scale only -- no new palette is introduced here. */
:root {
    --vf-search-ring: color-mix(in oklab, var(--primary-500, #6366f1) 42%, transparent);
    --vf-search-rail: var(--primary-600, #4f46e5);
    --vf-search-veil: rgb(9 9 11 / 0.28);
}

.dark {
    --vf-search-ring: color-mix(in oklab, var(--primary-400, #818cf8) 38%, transparent);
    --vf-search-rail: var(--primary-400, #818cf8);
    --vf-search-veil: rgb(2 6 23 / 0.48);
}

/* --- Field aura ------------------------------------------------------------
   The "expansion" of the field is drawn, not applied: a ring that grows around
   it rather than a scale held on the element the operator is typing into. A
   transform living on a focused text input softens its glyphs for as long as the
   focus lasts, which on a search field is exactly the wrong trade.

   The pseudo-element paints above the input (positioned descendants paint after
   in-flow content) but its interior is transparent -- only the ring and its
   falloff land, and \`pointer-events: none\` keeps the field clickable. */

.fi-global-search-field[${FIELD_FLAG}] {
    position: relative;
}

.fi-global-search-field[${FIELD_FLAG}]::after {
    content: '';
    position: absolute;
    inset: -5px;
    border-radius: 0.75rem;
    pointer-events: none;
    opacity: var(--vf-search-aura);
    transform: scale(calc(0.985 + var(--vf-search-aura) * 0.015));
    box-shadow:
        0 0 0 1px var(--vf-search-ring),
        0 12px 34px -12px var(--vf-search-ring);
}

/* --- Veil ------------------------------------------------------------------
   The page receding while the palette is open. Never interactive: this is an
   operations panel and a full-viewport overlay that can swallow a click is a way
   to lose an action, so it is painted and nothing more. Its stacking sits under
   the chrome that owns the search field and under modals (z-40+), so an action
   opened from a result is never dimmed by it. */

.vf-search-veil {
    position: fixed;
    inset: 0;
    z-index: var(--vf-search-veil-z, 25);
    pointer-events: none;
    background-color: var(--vf-search-veil);
    opacity: 0;
}

/* backdrop-filter re-rasterises the whole viewport every frame it is composited.
   Only the top tier gets it, and only at a radius small enough to stay a hint. */
.vf-search-veil[data-vf-blur] {
    backdrop-filter: blur(3px);
}

/* --- Highlight rail --------------------------------------------------------
   Deliberately a rail at the row's leading edge rather than a filled block: a
   fill would have to be painted over the result text (it lives at body level and
   cannot get behind it), and dimming a result while the operator reads it is not
   a trade worth making. Filament already draws the highlight itself with
   \`focus-within:bg-gray-50\`; this adds the travel. */

.vf-search-cursor {
    position: fixed;
    width: 3px;
    border-radius: 999px;
    background-color: var(--vf-search-rail);
    box-shadow: 0 0 12px -2px var(--vf-search-rail);
    pointer-events: none;
    opacity: 0;
    z-index: var(--vf-search-cursor-z, 31);
}
`

export function searchPalette({ gsap, MOTION }) {
    if (typeof document === 'undefined' || !document.body) {
        return null
    }

    const boot = ++generation

    // Before anything is measured or created: undo the previous run. This runs
    // even when the module then bails out, so navigating to a page without
    // global search still clears whatever the last page left behind.
    sweep()

    const flags = window.__vfMotion ?? {}

    // The runtime already skips every module under reduced motion; this is the
    // governor's own verdict, which can differ, and is read defensively because
    // perf.js may not have published anything at all.
    if (flags.reduced) {
        return null
    }

    const root = findRoot()

    if (!root) {
        return null
    }

    const tier = flags.tier ?? 'medium'
    const low = tier === 'low'
    const high = tier === 'high'
    const cap = ROW_CAP[tier] ?? ROW_CAP.medium

    gsap.registerPlugin(Flip)

    /*
     * `gsap.context()` with no argument hands back the context we are running
     * inside -- the runtime's. Tweens created later, from an observer or a focus
     * handler, would otherwise belong to no context: nothing would revert them,
     * and the inline styles they left behind would survive the next navigation
     * frozen mid-animation. `owned()` puts them back under the runtime's control.
     */
    const host = gsap.context()

    /* The result is fished out through a closure rather than returned: GSAP
       treats a function returned from `context.add()` as a cleanup callback and
       would invoke it on revert. */
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

    // gsap.context() reverts tweens, not listeners, observers, timers or
    // appended nodes. This nested context exists only so the runtime's revert()
    // reaches the disposer.
    gsap.context(() => () => scope.dispose())

    injectStylesheet(scope)

    const alive = () => boot === generation

    /* --------------------------------------------------------------------- */
    /* Appended chrome                                                        */
    /* --------------------------------------------------------------------- */

    const veil = low ? null : make('div', 'vf-search-veil', scope)

    if (veil) {
        if (high) {
            veil.setAttribute('data-vf-blur', '')
        }

        /* Where the field lives decides what the veil is allowed to cover. In
           the topbar (z-30) it can sit at 25 and dim everything below. In the
           sidebar it has to duck under the sidebar's own desktop layer (z-20),
           or it would dim the field it is meant to be lifting out of. */
        veil.style.setProperty('--vf-search-veil-z', root.closest('.fi-sidebar') ? '19' : '25')
        gsap.set(veil, { autoAlpha: 0 })
    }

    const rail = make('div', 'vf-search-cursor', scope)
    gsap.set(rail, { autoAlpha: 0 })

    /* --------------------------------------------------------------------- */
    /* Highlight rail                                                         */
    /* --------------------------------------------------------------------- */

    let railShown = false
    let railFlip = null
    let railFrame = 0

    /**
     * Where the rail should stand for a given result link, in viewport
     * coordinates, already clipped to what is actually visible.
     *
     * All the reads happen together and nothing is written until they are done,
     * so this costs one layout rather than one per query.
     *
     * @param {HTMLElement} link
     * @returns {?{top: number, height: number, left: ?number, right: ?number}}
     */
    const geometryFor = (link) => {
        const ctn = link.closest('.fi-global-search-results-ctn')

        if (!ctn) {
            return null
        }

        const row = link.closest('.fi-global-search-result') ?? link
        const header = link
            .closest('.fi-global-search-result-group')
            ?.querySelector('.fi-global-search-result-group-header')

        const rowRect = row.getBoundingClientRect()
        const ctnRect = ctn.getBoundingClientRect()
        const headerRect = header ? header.getBoundingClientRect() : null

        // A detached node (the morph replaced the results under us) measures as
        // all zeroes, and so does a container Alpine has hidden.
        if (!rowRect.height || !ctnRect.height) {
            return null
        }

        /* The group header is `sticky top-0`, so on a scrolled list it sits
           physically over the top of the row it belongs to. The rail is a
           body-level fixed node and would otherwise paint across it. */
        let ceiling = ctnRect.top

        if (headerRect && headerRect.bottom > ceiling && headerRect.top < rowRect.bottom) {
            ceiling = headerRect.bottom
        }

        const top = Math.max(rowRect.top, ceiling)
        const height = Math.min(rowRect.bottom, ctnRect.bottom) - top

        // Scrolled out of the container's visible band: there is nothing left to
        // mark, so the rail is hidden rather than clamped to a sliver.
        if (height < 6) {
            return null
        }

        const rtl = getComputedStyle(ctn).direction === 'rtl'

        return {
            top: Math.round(top),
            height: Math.round(height),
            left: rtl ? null : Math.round(ctnRect.left + 3),
            right: rtl ? Math.round(window.innerWidth - ctnRect.right + 3) : null,
        }
    }

    const placeRail = (geometry) => {
        gsap.set(rail, {
            top: geometry.top,
            height: geometry.height,
            left: geometry.left === null ? 'auto' : geometry.left,
            right: geometry.right === null ? 'auto' : geometry.right,
        })
    }

    const hideRail = () => {
        if (railFlip) {
            railFlip.kill()
            railFlip = null
        }

        if (!railShown) {
            return
        }

        railShown = false

        owned(() =>
            gsap.to(rail, {
                autoAlpha: 0,
                duration: 0.16,
                ease: MOTION.easeSoft,
                overwrite: 'auto',
            }),
        )
    }

    /**
     * Move the rail onto `link`. The first appearance is placed outright and
     * faded up -- a rail that flies in from nowhere reads as a stray element --
     * and every move after that is a FLIP, so the travel is a transform on the
     * compositor rather than `top`/`height` re-laid out per frame.
     */
    const moveRail = (link) => {
        const geometry = geometryFor(link)

        if (!geometry) {
            hideRail()

            return
        }

        if (!railShown) {
            placeRail(geometry)
            railShown = true
        } else {
            /* Arrow keys repeat far faster than a travel finishes. The in-flight
               flip is killed rather than queued: Flip.getState then records
               wherever the rail actually is, and the new travel starts from
               there instead of from a position it never reached. */
            if (railFlip) {
                railFlip.kill()
                railFlip = null
            }

            const state = Flip.getState(rail)
            placeRail(geometry)

            owned(() => {
                railFlip = Flip.from(state, {
                    duration: low ? 0.16 : 0.26,
                    ease: MOTION.ease,
                    // Height differences ride on scaleY instead of being laid
                    // out every frame. Result rows are near-uniform, so the
                    // scale factor stays within a few percent of 1 and the
                    // rail's rounded caps do not visibly distort.
                    scale: true,
                    onComplete: () => {
                        railFlip = null
                    },
                })

                return railFlip
            })
        }

        // `overwrite: 'auto'` only kills tweens of the same properties, so this
        // cannot disturb the flip's transform.
        owned(() =>
            gsap.to(rail, {
                autoAlpha: 1,
                duration: 0.16,
                ease: MOTION.easeSoft,
                overwrite: 'auto',
            }),
        )
    }

    const focusedLink = () => {
        const active = document.activeElement

        return active && active.closest ? active.closest('.fi-global-search-result-link') : null
    }

    /**
     * Re-place the rail without animating it. This is for anything that moves the
     * list under a highlight that has not itself changed -- the container
     * scrolling as focus walks past its fold, the window scrolling, a resize.
     * Coalesced to one frame, because scroll fires far faster than paint.
     */
    const resyncRail = () => {
        if (!railShown || railFrame) {
            return
        }

        railFrame = requestAnimationFrame(() => {
            railFrame = 0

            if (!alive() || !railShown) {
                return
            }

            const link = focusedLink()
            const geometry = link ? geometryFor(link) : null

            if (!geometry) {
                hideRail()

                return
            }

            if (railFlip) {
                railFlip.kill()
                railFlip = null
            }

            placeRail(geometry)
        })
    }

    scope.add(() => {
        if (railFrame) {
            cancelAnimationFrame(railFrame)
            railFrame = 0
        }
    })

    // Capture, so the results container's own scroll is caught too -- scroll does
    // not bubble, but it does reach a capturing listener on window.
    scope.on(window, 'scroll', resyncRail, { passive: true, capture: true })
    scope.on(window, 'resize', resyncRail, { passive: true })
    scope.on(document, 'livewire:update', resyncRail)

    /* --------------------------------------------------------------------- */
    /* Field: veil and aura                                                   */
    /* --------------------------------------------------------------------- */

    let activeField = null

    /* `focusin` is a document-level listener, so `deactivate` is reached every
       time anything anywhere in the panel takes focus -- a button, a table
       filter, a form field. Without this it would mint a veil tween per click
       for a veil that is already down. */
    let engaged = false

    /** Drop the aura and its marker from a field this module no longer owns. */
    const forget = (field) => {
        if (!field) {
            return
        }

        gsap.killTweensOf(field)
        field.removeAttribute(FIELD_FLAG)
        field.style.removeProperty('--vf-search-aura')
    }

    const activate = (field) => {
        if (activeField && activeField === field) {
            return
        }

        // A Livewire morph can hand back a different wrapper node for the same
        // field. Whatever we were lighting up before is not it any more.
        if (activeField && field && activeField !== field) {
            forget(activeField)
        }

        activeField = field ?? activeField
        engaged = true

        if (veil) {
            owned(() =>
                gsap.to(veil, {
                    autoAlpha: 1,
                    duration: 0.3,
                    ease: MOTION.easeSoft,
                    overwrite: 'auto',
                }),
            )
        }

        if (low || !activeField) {
            return
        }

        activeField.setAttribute(FIELD_FLAG, '')

        /* `to`, not `fromTo`. Focus can come back while the fade-out is still
           running, and a fromTo would snap the aura to zero before ramping it up
           again -- a visible blink on the element that just got focus. Reading
           the current value back is only possible because the property is
           registered above; an unregistered one computes to an empty string. */
        owned(() =>
            gsap.to(activeField, {
                '--vf-search-aura': 1,
                duration: 0.5,
                ease: MOTION.ease,
                overwrite: 'auto',
            }),
        )
    }

    const deactivate = () => {
        if (!engaged) {
            return
        }

        engaged = false
        hideRail()

        if (veil) {
            owned(() =>
                gsap.to(veil, {
                    autoAlpha: 0,
                    duration: 0.22,
                    ease: MOTION.easeSoft,
                    overwrite: 'auto',
                }),
            )
        }

        const field = activeField
        activeField = null

        if (!field || !field.isConnected) {
            return
        }

        owned(() =>
            gsap.to(field, {
                '--vf-search-aura': 0,
                duration: 0.24,
                ease: MOTION.easeSoft,
                overwrite: 'auto',
                onComplete: () => {
                    // Only now: dropping the attribute mid-fade takes the whole
                    // pseudo-element away and the aura blinks out instead of
                    // fading. Guarded, because focus may have come back since.
                    if (field !== activeField) {
                        field.removeAttribute(FIELD_FLAG)
                        field.style.removeProperty('--vf-search-aura')
                    }
                },
            }),
        )
    }

    scope.on(document, 'focusin', (event) => {
        const target = event.target

        if (!target || !target.closest) {
            return
        }

        const ctn = target.closest('.fi-global-search-ctn')

        if (!ctn) {
            deactivate()

            return
        }

        activate(ctn.querySelector('.fi-global-search-field'))

        const link = target.closest('.fi-global-search-result-link')

        if (link) {
            moveRail(link)
        } else {
            // Focus came back to the field itself: no result is highlighted any
            // more, so the rail has nothing to mark.
            hideRail()
        }
    })

    /*
     * `focusout` fires before focus lands, and blurring to nothing (a click on
     * empty space) produces no matching `focusin` at all -- so the check has to
     * happen a frame later, against whatever ended up focused. A frame is far too
     * short to be felt and is nowhere near the keystroke path.
     */
    let blurFrame = 0

    scope.on(document, 'focusout', () => {
        if (blurFrame) {
            return
        }

        blurFrame = requestAnimationFrame(() => {
            blurFrame = 0

            if (!alive()) {
                return
            }

            const active = document.activeElement

            if (!active || !active.closest || !active.closest('.fi-global-search-ctn')) {
                deactivate()
            }
        })
    })

    scope.add(() => {
        if (blurFrame) {
            cancelAnimationFrame(blurFrame)
            blurFrame = 0
        }
    })

    // Filament closes the dropdown on Escape but leaves the field focused. The
    // rail has nothing left to point at; the veil stays, because the palette is
    // still the thing the operator is using.
    // Passive: this listener sees every keystroke in the panel, so it is
    // declared incapable of calling preventDefault and does nothing but one
    // string comparison.
    scope.on(
        window,
        'keydown',
        (event) => {
            if (event.key === 'Escape') {
                hideRail()
            }
        },
        { passive: true },
    )

    // A hidden tab must not keep paying for a full-viewport backdrop filter.
    scope.on(document, 'visibilitychange', () => {
        if (document.hidden) {
            deactivate()
        }
    })

    // The stylesheet goes with the teardown, which makes the marker inert rather
    // than wrong -- but leaving it would mean a later run's sweep is the first
    // thing to tidy it, and a run that never comes never does.
    scope.add(() => forget(activeField))

    /* --------------------------------------------------------------------- */
    /* Results: cascade, container, empty state                               */
    /* --------------------------------------------------------------------- */

    /* Rows the current cascade owns, so a cascade that is superseded can hand
       them straight back to the stylesheet instead of leaving them mid-fade. */
    let cascadeRows = []
    let cascadeTween = null
    let cascadeToken = 0

    const CASCADE = 'transform,opacity,willChange'

    const abortCascade = () => {
        if (cascadeTween) {
            cascadeTween.kill()
            cascadeTween = null
        }

        const live = cascadeRows.filter((row) => row.isConnected)

        if (live.length) {
            gsap.set(live, { clearProps: CASCADE })
        }

        cascadeRows = []
    }

    scope.add(abortCascade)

    /**
     * Results must not depend on a tween completing to become readable. This runs
     * on the wall clock and force-clears the same properties; it no-ops if a
     * newer cascade has taken over, because that one owns the rows now.
     *
     * @param {number} token
     * @param {HTMLElement[]} items
     * @param {number} budget milliseconds
     */
    const armFailsafe = (token, items, budget) => {
        scope.timer(() => {
            if (!alive() || token !== cascadeToken) {
                return
            }

            const live = items.filter((item) => item.isConnected)

            if (live.length) {
                gsap.set(live, { clearProps: `clipPath,${CASCADE}` })
            }

            // Those rows just moved by up to 8px as their transforms were
            // dropped. If focus landed on one mid-cascade the rail is now
            // measuring the old position.
            resyncRail()
        }, budget)
    }

    /**
     * Animate one freshly rendered result set.
     *
     * @param {HTMLElement} ctn the results container
     * @param {boolean} opened true when the container itself is new
     */
    const reveal = (ctn, opened) => {
        // Whatever the previous query was showing is either gone or about to be
        // overwritten. Either way it is released before this set is touched, so
        // no row is ever left holding a stale opacity.
        abortCascade()

        const token = ++cascadeToken

        if (opened) {
            /*
             * The container carries Tailwind's `transition` utility, which covers
             * transform and opacity. Left in place it re-times every value GSAP
             * writes and the open smears instead of easing. It is switched off
             * inline for the length of the tween and handed back by clearProps --
             * Alpine's own leave transition needs it.
             *
             * The transform is cleared too. Leaving it would be harmless (the
             * container already carries Filament's own `translateZ(0)`, so it is a
             * containing block either way), but result actions render their modals
             * inline in here and there is no reason to add a second reason for a
             * fixed overlay to be trapped.
             */
            owned(() => {
                gsap.set(ctn, { transition: 'none', willChange: 'transform, opacity' })

                /* Opacity is a separate, much shorter tween than the transform.
                   The rows inside are fading up at the same time, and two nested
                   opacity ramps MULTIPLY -- run at the same length, a result
                   would sit near a quarter alpha through the middle of its own
                   arrival. The panel is opaque long before it has finished
                   settling, so only the cascade governs when a row is legible. */
                return gsap
                    .timeline()
                    .fromTo(
                        ctn,
                        { y: -8, scale: 0.985 },
                        {
                            y: 0,
                            scale: 1,
                            duration: low ? 0.18 : 0.3,
                            ease: MOTION.ease,
                            transformOrigin: 'top center',
                            overwrite: 'auto',
                            clearProps: 'transform,transformOrigin,transition,willChange',
                        },
                        0,
                    )
                    .fromTo(
                        ctn,
                        { opacity: 0 },
                        {
                            opacity: 1,
                            duration: 0.16,
                            ease: MOTION.easeSoft,
                            overwrite: 'auto',
                            clearProps: 'opacity',
                        },
                        0,
                    )
            })
        }

        const empty = ctn.querySelector('.fi-global-search-no-results-message')

        if (empty) {
            /* "Nothing found" is the one line in the palette that should not
               arrive with a snap -- it is already an unwelcome answer. It is wiped
               down from its own top edge instead, with opacity on a separate,
               shorter ramp so the words are legible before the wipe has finished.
               The inline clip-path is cleared or it would keep clipping this
               paragraph for as long as the dropdown lives. */
            owned(() =>
                low
                    ? gsap.fromTo(
                          empty,
                          { opacity: 0 },
                          {
                              opacity: 1,
                              duration: 0.2,
                              ease: MOTION.easeSoft,
                              clearProps: 'opacity',
                          },
                      )
                    : gsap
                          .timeline()
                          .fromTo(
                              empty,
                              { clipPath: 'inset(0% 0% 100% 0%)', y: -6 },
                              {
                                  clipPath: 'inset(0% 0% 0% 0%)',
                                  y: 0,
                                  duration: 0.44,
                                  ease: MOTION.ease,
                                  clearProps: 'clipPath,transform,willChange',
                              },
                              0,
                          )
                          .fromTo(
                              empty,
                              { opacity: 0 },
                              {
                                  opacity: 1,
                                  duration: 0.26,
                                  ease: MOTION.easeSoft,
                                  clearProps: 'opacity',
                              },
                              0,
                          ),
            )

            // Clipped and transparent until a tween says otherwise, so it gets a
            // failsafe of its own.
            armFailsafe(token, [empty], 900)

            return
        }

        /*
         * Headers and links in one query, so they come back in document order and
         * the cascade runs down the list as it reads. The row `<li>` itself is
         * never a target -- it is what result actions, and therefore their modals,
         * are rendered inside.
         */
        const items = Array.from(
            ctn.querySelectorAll(
                '.fi-global-search-result-group-header, .fi-global-search-result-link',
            ),
        ).slice(0, cap)

        if (!items.length) {
            return
        }

        cascadeRows = items

        const duration = low ? 0.18 : 0.3

        owned(() => {
            cascadeTween = gsap.fromTo(
                items,
                { y: 8, opacity: 0 },
                {
                    y: 0,
                    opacity: 1,
                    duration,
                    ease: MOTION.ease,
                    /* An `amount`, not an `each`: two results and twelve results
                       both finish spreading inside the same fixed window, so the
                       last row is never further from readable than the first. */
                    stagger: low ? 0 : { amount: 0.12, from: 'start' },
                    overwrite: 'auto',
                    clearProps: CASCADE,
                    onComplete: () => {
                        if (token === cascadeToken) {
                            cascadeTween = null
                            cascadeRows = []
                        }

                        // clearProps has just dropped the rows back onto their
                        // resting position; a rail placed against a mid-cascade
                        // measurement would be left a few pixels high.
                        resyncRail()
                    },
                },
            )

            return cascadeTween
        })

        armFailsafe(token, items, (duration + 0.12) * 1000 + 400)
    }

    /* --------------------------------------------------------------------- */
    /* Watching for results                                                   */
    /* --------------------------------------------------------------------- */

    /**
     * A cheap fingerprint of what the dropdown is currently showing. Two
     * different queries that resolve to the same records produce the same
     * fingerprint and are deliberately not re-cascaded -- re-flashing rows the
     * operator is already reading is noise, not feedback.
     *
     * @param {?HTMLElement} ctn
     * @returns {string}
     */
    const fingerprint = (ctn) => {
        if (!ctn) {
            return ''
        }

        const links = ctn.querySelectorAll('.fi-global-search-result-link')

        if (!links.length) {
            return ctn.querySelector('.fi-global-search-no-results-message') ? 'empty' : ''
        }

        return `${links.length}|${links[0].getAttribute('href') ?? ''}|${
            links[links.length - 1].getAttribute('href') ?? ''
        }`
    }

    let lastCtn = null
    let lastPrint = ''
    let syncFrame = 0
    let paintFrame = 0

    const stopPaintGate = () => {
        if (paintFrame) {
            cancelAnimationFrame(paintFrame)
            paintFrame = 0
        }
    }

    /**
     * Alpine mounts the results container with `x-show` false and opens it on the
     * next tick, so the node exists for a frame or two with no box at all.
     * Starting the reveal then would spend it entirely behind `display: none`.
     * Capped, and the cap runs the reveal anyway -- a reveal on a hidden element
     * still ends at its natural state, which is the only outcome that matters.
     */
    const whenPainted = (ctn, run) => {
        stopPaintGate()

        const startedAt = performance.now()

        const step = () => {
            paintFrame = 0

            if (!alive() || !ctn.isConnected) {
                return
            }

            if (ctn.getBoundingClientRect().height > 0 || performance.now() - startedAt > 300) {
                run()

                return
            }

            paintFrame = requestAnimationFrame(step)
        }

        paintFrame = requestAnimationFrame(step)
    }

    scope.add(stopPaintGate)

    const sync = () => {
        const ctn = root.querySelector('.fi-global-search-results-ctn')

        if (!ctn) {
            // The field was cleared: Livewire drops the whole block, so there is
            // nothing left for the rail to mark and nothing to remember.
            stopPaintGate()
            abortCascade()
            hideRail()
            lastCtn = null
            lastPrint = ''

            return
        }

        const print = fingerprint(ctn)
        const opened = ctn !== lastCtn

        if (!opened && print === lastPrint) {
            return
        }

        lastCtn = ctn
        lastPrint = print

        // The highlighted row is being replaced underneath the rail.
        hideRail()

        if (print === '') {
            // Rendered, but with neither results nor the no-results line in it
            // yet. Nothing to reveal; the next morph carries the content.
            return
        }

        whenPainted(ctn, () => reveal(ctn, opened))
    }

    /*
     * childList and characterData only, deliberately. GSAP writes inline styles,
     * which are attribute mutations -- observing those would make every frame of
     * a cascade re-enter this observer. Livewire's morph, on the other hand,
     * always shows up as replaced nodes or rewritten text.
     */
    if (typeof MutationObserver === 'function') {
        const observer = new MutationObserver(() => {
            if (syncFrame) {
                return
            }

            // Coalesced to one pass per frame: a single Livewire morph produces
            // dozens of mutation records, and each one must not cost a DOM walk.
            syncFrame = requestAnimationFrame(() => {
                syncFrame = 0

                if (alive()) {
                    sync()
                }
            })
        })

        observer.observe(root, { childList: true, subtree: true, characterData: true })

        scope.add(() => {
            observer.disconnect()

            if (syncFrame) {
                cancelAnimationFrame(syncFrame)
                syncFrame = 0
            }
        })
    }

    // A dropdown can already be on screen when the module runs -- toggling the
    // reduced-motion preference re-boots everything without touching the DOM.
    sync()

    return null
}

/* ------------------------------------------------------------------------- */
/* Helpers                                                                    */
/* ------------------------------------------------------------------------- */

/**
 * The global search component, if this page has one. A panel can render it in
 * the topbar or in the sidebar, and a render hook can leave an empty container
 * behind, so the one that actually owns an input is the one that counts.
 *
 * @returns {?HTMLElement}
 */
function findRoot() {
    const candidates = document.querySelectorAll('.fi-global-search-ctn')

    for (const candidate of candidates) {
        if (candidate.querySelector('.fi-global-search-field input')) {
            return candidate
        }
    }

    return null
}

/**
 * Undo the previous run's DOM. Called before this run creates anything, so a
 * navigation to a page with no global search still clears the veil, and a run
 * that threw halfway through cannot leave one dimming the panel forever.
 */
function sweep() {
    document.querySelectorAll(`[${NODE_FLAG}]`).forEach((node) => node.remove())

    document.querySelectorAll(`[${FIELD_FLAG}]`).forEach((field) => {
        field.removeAttribute(FIELD_FLAG)
        field.style.removeProperty('--vf-search-aura')
    })
}

/**
 * Create a body-level node, flagged and registered for teardown in one step.
 *
 * @param {string} tag
 * @param {string} className
 * @param {{add: Function}} scope
 * @returns {HTMLElement}
 */
function make(tag, className, scope) {
    const node = document.createElement(tag)
    node.className = className
    node.setAttribute(NODE_FLAG, '')
    node.setAttribute('aria-hidden', 'true')
    document.body.appendChild(node)
    scope.add(() => node.remove())

    return node
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
 * A bag of teardown callbacks. Every listener, timer and appended node in this
 * file goes through one, so a single revert unwinds all of it.
 *
 * Timers deregister themselves when they fire: a long session can arm one
 * failsafe per query, and a list that only ever grows is its own leak.
 */
function createScope() {
    const disposers = []
    const timers = new Set()

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
        timer(fn, ms) {
            const id = window.setTimeout(() => {
                timers.delete(id)
                fn()
            }, ms)

            timers.add(id)
        },
        dispose() {
            timers.forEach((id) => window.clearTimeout(id))
            timers.clear()

            while (disposers.length) {
                try {
                    disposers.pop()()
                } catch (error) {
                    // A failing disposer must not strand the ones behind it --
                    // one of them is the veil.
                    console.warn('[vpn-forge motion] search cleanup failed', error)
                }
            }
        },
    }
}
