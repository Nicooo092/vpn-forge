/**
 * Animated dark/light theme transition.
 * -------------------------------------
 * One effect: the new theme arrives as a circle that opens out of the switcher
 * button the operator just pressed, instead of the page hard-cutting from one
 * palette to the other. It works in both directions, and it works from the real
 * coordinates of the click rather than from a guessed centre.
 *
 * WHAT FILAMENT ACTUALLY DOES, because everything here is built around it.
 * `components/theme-switcher/index.blade.php` is an Alpine component holding one
 * `theme` string ('light' | 'dark' | 'system'). Clicking a `.fi-theme-switcher-btn`
 * assigns it; a `$watch` on that assignment dispatches a bubbling `theme-changed`
 * CustomEvent. `filament/resources/js/dark-mode.js` listens for it **on window**,
 * writes localStorage, resolves 'system' against `prefers-color-scheme`, and puts
 * the result in `Alpine.store('theme')`. A separate `Alpine.effect` watches that
 * store and adds or removes `dark` on `<html>`. That last step is the flip.
 *
 * THE PROBLEM. Both animation techniques available here need the flip to happen
 * at a moment they choose: the View Transitions API has to snapshot the old page
 * *before* the class changes and the new page *after*, and the fallback has to
 * have the screen covered before the class changes or the swap is visible. But
 * the flip lands inside an Alpine effect, on a microtask, well out of reach.
 *
 * THE INTERCEPTION. `theme-changed` is dispatched from an element inside the
 * document, so a CAPTURE-phase listener on `window` runs before Filament's
 * bubble-phase one no matter which was registered first. This module takes the
 * event there, cuts it off with `stopImmediatePropagation()`, and re-dispatches
 * an identical event down the same path once the reveal is in position. The flip
 * is therefore still performed by Filament's own code, on Filament's own
 * schedule -- it is only delayed by a few hundred milliseconds.
 *
 * Cutting the vendor's event off is the riskiest thing this file does, so it is
 * hedged three ways: the interception only happens when the class is genuinely
 * about to change, the replay is invoked from every exit path including a
 * wall-clock failsafe, and the `dark` class is written by hand alongside the
 * replay so the theme lands even if nothing downstream is listening at all.
 * Failing to animate is a shrug; failing to switch the theme is a broken panel.
 *
 * WHAT IS DELIBERATELY NOT COVERED. Changing the OS colour scheme while the
 * panel is set to 'system' bypasses `theme-changed` entirely -- Filament writes
 * the store directly. Intercepting that would mean patching Alpine's store, and
 * an unattended flip has no button to originate from anyway, so it keeps
 * Filament's instant swap.
 *
 * @param {{gsap: import('gsap').gsap, ScrollTrigger: object, MOTION: object}} api
 */

/* Marks every node this module puts in the document, so an interrupted run can
   be swept by the next one. gsap.context() reverts tweens; it has no idea we
   appended anything. */
const OWNED = 'data-vf-theme'

const STYLE_ID = 'vf-theme'

/* Set on the event we re-dispatch, so the capture listener recognises its own
   echo and lets it through to Filament instead of intercepting it again. */
const REPLAY = '__vfThemeReplay'

/* CSS mirror of MOTION.easeSoft. motion.css names this `--vf-ease-soft` and
   reserves it for "long, large changes", on the grounds that full expo reads as
   a snap at that size -- a circle crossing the whole viewport is exactly that
   case. It has to be spelled as a bezier because the View Transitions path
   animates a pseudo-element, which only the Web Animations API can address;
   GSAP cannot reach `::view-transition-new(root)`. */
const EASE_CSS = 'cubic-bezier(0.33, 1, 0.68, 1)'

/* The panel's body colour in each theme, straight off Filament's own `.fi-body`
   rule (`bg-gray-50 dark:bg-gray-950`). Read through the palette variables
   FilamentAsset emits inline at runtime, with the same literal fallbacks
   motion.css uses in case that <style> has not been parsed yet. Only the
   fallback path needs these -- the View Transitions path paints a real snapshot
   of the page and never has to guess a colour. */
const SURFACE = {
    light: 'var(--gray-50, oklch(0.985 0 0))',
    dark: 'var(--gray-950, oklch(0.141 0.005 285.823))',
}

/* How long a recorded click stays usable as an origin. The Alpine `$watch` fires
   on the microtask straight after the click handler, so this only has to survive
   one tick; it is generous purely so a stalled frame does not cost the origin
   and drop the reveal back to a corner. */
const ORIGIN_TTL = 1500

/* Above the panel's own stacking: the wash has to cover open modals and
   notifications too, or the flip shows through them. */
const VEIL_Z = 2147483646

/**
 * The reveal currently in flight, if any. Module scope on purpose: the runtime
 * tears this module down and re-runs it on every `livewire:navigated`, and a
 * reveal caught mid-flight by that has to be finishable by the *next* run --
 * the closure that owned it is gone by then.
 */
let live = null

export function themeSwitch({ gsap, MOTION }) {
    if (typeof document === 'undefined' || !document.body) {
        return null
    }

    // Anything the previous run left behind: a reveal it never finished, then
    // its nodes. Order matters -- finishing writes to those nodes.
    finishPending()
    sweep()

    const governor = window.__vfMotion
    const tier = governor?.tier ?? 'medium'

    // Reduced motion and the low tier both get Filament's untouched behaviour.
    // Returning here means no capture listener is ever registered, so the vendor
    // handler is the only one on the event and the swap is instant -- which is
    // exactly the required fallback, at zero cost.
    if (governor?.reduced || tier === 'low') {
        return null
    }

    // Dark mode can be disabled or forced in the panel config, and the auth
    // screens have no top bar at all. No switcher, no event, nothing to do.
    if (!document.querySelector('.fi-theme-switcher-btn')) {
        return null
    }

    /* `gsap.context()` with no argument hands back the context we are running
       inside -- the runtime's. Tweens created later, from a click, would
       otherwise belong to no context and would survive a navigation. */
    const host = gsap.context()

    const scope = createScope()

    // gsap.context() reverts tweens, not listeners or appended DOM. This nested
    // context exists only so the runtime's revert() reaches the disposer.
    gsap.context(() => () => {
        finishPending()
        scope.dispose()
    })

    /* The stylesheet stays empty until a flip arms it. Its rules describe the
       view-transition pseudo-elements, which only exist during a transition, and
       leaving live `::view-transition-*` declarations lying around would apply
       to any future transition on the page. */
    const style = document.createElement('style')
    style.id = STYLE_ID
    style.setAttribute(OWNED, 'style')
    document.head.appendChild(style)
    scope.add(() => style.remove())

    /* Where the last switcher press happened. Recorded on the click rather than
       measured when the flip arrives, because the switcher lives inside the user
       menu and the button's own handler is `(theme = '...') && close()` -- by the
       time the `$watch` fires, the dropdown holding the button is already on its
       way out. */
    let origin = null

    scope.on(
        document,
        'click',
        (event) => {
            const target = event.target
            const button = target && target.closest ? target.closest('.fi-theme-switcher-btn') : null

            if (!button) {
                return
            }

            origin = pointOf(event, button)
        },
        { capture: true, passive: true },
    )

    scope.on(
        window,
        'theme-changed',
        (event) => {
            // Our own replay, on its way to Filament's listener.
            if (event[REPLAY]) {
                return
            }

            const detail = event.detail
            const next = resolvesToDark(detail)

            /* Two no-ops that must fall straight through, untouched.
               A value this module does not understand is Filament's business,
               not ours. And an event that resolves to the class the document
               already has is every page load and every SPA navigation: the
               switcher's `x-init` assigns `theme` from localStorage, which trips
               its own `$watch`. Revealing there would flash the whole panel on
               arrival for no reason. */
            if (next === null || next === document.documentElement.classList.contains('dark')) {
                return
            }

            /* A hidden tab has nothing to reveal, and a second flip on top of a
               running one would snapshot a page mid-transition. Both finish
               whatever is in flight and then let this event through to
               Filament -- rapid toggling gets instant swaps, which is the right
               answer for someone hammering the control. */
            if (document.hidden || live) {
                finishPending()

                return
            }

            // Past here this module owns the flip and is responsible for
            // completing it.
            event.stopImmediatePropagation()

            startReveal({
                gsap,
                MOTION,
                host,
                style,
                detail,
                next,
                target: event.target,
                point: originPoint(origin),
                span: tier === 'high' ? MOTION.slow * 0.62 : MOTION.base * 0.72,
            })
        },
        { capture: true },
    )

    // Backgrounding the tab suspends rendering: the reveal would resume from
    // wherever it froze, minutes later, on a page the operator has come back to.
    scope.on(document, 'visibilitychange', () => {
        if (document.hidden) {
            finishPending()
        }
    })

    return null
}

/* ------------------------------------------------------------------------- */
/* The reveal                                                                 */
/* ------------------------------------------------------------------------- */

/**
 * Run one theme flip behind a circular reveal.
 *
 * Both paths share the same two callbacks. `commit` performs the flip and is
 * called exactly once, at the moment the technique in use wants the DOM to
 * change. `settle` winds everything up and is idempotent, so the timer, the
 * browser and the tween can all race to call it.
 *
 * @param {{gsap: object, MOTION: object, host: ?object, style: HTMLStyleElement,
 *          detail: string, next: boolean, target: ?EventTarget,
 *          point: {x: number, y: number}, span: number}} run
 */
function startReveal(run) {
    const { gsap, MOTION, host, style, detail, next, target, point, span } = run

    const radius = coverRadius(point.x, point.y)

    let committed = false
    let settled = false
    let guard = 0
    let veil = null

    const commit = () => {
        if (committed) {
            return
        }

        committed = true

        /* Written by hand, synchronously, rather than left to the Alpine effect
           that normally does it. Inside a view transition the snapshot is taken
           as soon as this callback returns, and Alpine's reactivity flushes on a
           microtask -- the class would land after the picture was taken. The
           replay below sets the same class a tick later through Filament's own
           path, so the two agree rather than fight. It is also the reason a
           dead Alpine still leaves the operator with a working toggle. */
        document.documentElement.classList.toggle('dark', next)

        replay(target, detail)
    }

    const settle = () => {
        if (settled) {
            return
        }

        settled = true

        // Never leaves without flipping: this is the last exit, and every other
        // one funnels through it.
        commit()

        if (guard) {
            window.clearTimeout(guard)
            guard = 0
        }

        style.textContent = ''

        if (veil) {
            veil.remove()
            veil = null
        }

        if (live && live.settle === settle) {
            live = null
        }
    }

    live = { settle }

    /* The failsafe. The reveal hands control back to Filament from inside a
       browser callback or a tween completion, and neither is guaranteed: a
       transition can be abandoned, a context can be reverted mid-flight, a
       promise can be left unsettled by an engine bug. The one thing that may not
       be lost is the flip itself, so it also runs on the wall clock. */
    guard = window.setTimeout(settle, (span + 0.8) * 1000)

    /* --- Path 1: View Transitions ---------------------------------------- */

    if (typeof document.startViewTransition === 'function') {
        armSnapshotStyles(style, point)

        let transition = null

        try {
            transition = document.startViewTransition(commit)
        } catch (error) {
            // Chrome throws if a transition is already running. Fall through to
            // the veil rather than leaving the page unflipped.
            style.textContent = ''
        }

        if (transition) {
            transition.ready
                .then(() => {
                    /* The pseudo-element cannot be tweened by GSAP, so the
                       clip runs on the Web Animations API instead -- against the
                       same bezier the rest of the panel uses. `fill: forwards`
                       holds it open, otherwise it would snap back to the CSS
                       `circle(0px)` for the frame between this animation ending
                       and the pseudo-elements being torn down. */
                    document.documentElement.animate(
                        {
                            clipPath: [
                                `circle(0px at ${point.x}px ${point.y}px)`,
                                `circle(${radius}px at ${point.x}px ${point.y}px)`,
                            ],
                        },
                        {
                            duration: span * 1000,
                            easing: EASE_CSS,
                            fill: 'forwards',
                            pseudoElement: '::view-transition-new(root)',
                        },
                    )
                })
                .catch(() => {
                    // A skipped transition rejects `ready`. `finished` still
                    // settles, and the failsafe is still armed.
                })

            // `finished` settles however it ends -- completed, skipped or
            // aborted -- which is precisely when the pseudo-elements stop
            // existing and the arming rules stop meaning anything.
            transition.finished.then(settle, settle)

            return
        }
    }

    /* --- Path 2: the veil ------------------------------------------------- */

    /* Same gesture without snapshots: a disc of the *incoming* theme's body
       colour opens from the button until it covers the viewport, the flip
       happens underneath it, and the disc then fades off the finished page.
       Opaque throughout the growth on purpose -- a translucent wash would show
       the class swap happening through it. */

    veil = document.createElement('div')
    veil.setAttribute(OWNED, 'veil')
    veil.setAttribute('aria-hidden', 'true')
    veil.style.cssText = [
        'position:fixed',
        'inset:0',
        `z-index:${VEIL_Z}`,
        // Never in the way of a click, even for the frame it covers everything.
        'pointer-events:none',
        `background-color:${next ? SURFACE.dark : SURFACE.light}`,
        `clip-path:circle(0px at ${point.x}px ${point.y}px)`,
        'will-change:clip-path,opacity',
    ].join(';')

    document.body.appendChild(veil)

    /* A fixed element is positioned against its nearest transformed ancestor,
       not the viewport, and this panel's other motion modules do put transient
       transforms on page-level containers. A veil that landed somewhere other
       than the viewport would wash the wrong region, so the flip goes through
       unanimated instead. One forced layout, on a user's click.

       Measured against documentElement.clientWidth/Height, not innerWidth: a
       fixed box resolves against the initial containing block, which excludes a
       classic scrollbar. innerWidth includes it, and comparing to that would
       reject a perfectly placed veil on every desktop without overlay
       scrollbars. */
    const box = veil.getBoundingClientRect()
    const root = document.documentElement

    const covers =
        Math.abs(box.left) <= 1 &&
        Math.abs(box.top) <= 1 &&
        box.width >= root.clientWidth - 2 &&
        box.height >= root.clientHeight - 2

    if (!covers) {
        settle()

        return
    }

    const state = { r: 0 }

    owned(host, () =>
        gsap
            .timeline({ onComplete: settle })
            .to(state, {
                r: radius,
                duration: span,
                ease: MOTION.easeSoft,
                onUpdate() {
                    // The node is removed by settle(), and a tween can outlive
                    // that by a frame if the failsafe fired first.
                    if (veil) {
                        veil.style.clipPath = `circle(${state.r}px at ${point.x}px ${point.y}px)`
                    }
                },
                onComplete: commit,
            })
            .to(veil, {
                opacity: 0,
                // Exits are quicker than entrances: the operator has already
                // seen the answer by now.
                duration: span * 0.6,
                ease: MOTION.easeSoft,
            }),
    )
}

/**
 * Describe the view-transition pseudo-elements for one flip, with the origin
 * baked in as literal pixels.
 *
 * Written as a whole stylesheet per flip rather than as custom properties that
 * the pseudo tree inherits, because inheritance into that tree is a corner of
 * the spec with uneven support and getting it wrong here means a page-sized
 * flash of the wrong theme.
 *
 * @param {HTMLStyleElement} style
 * @param {{x: number, y: number}} point
 */
function armSnapshotStyles(style, point) {
    style.textContent = `
/* The UA stylesheet cross-fades the two snapshots with mix-blend-mode:
   plus-lighter, which on two fully opaque page-sized images blows out to white
   halfway through. Both UA animations are cancelled and the blending is put
   back to normal: the old snapshot simply holds still, full strength, while
   the new one is uncovered by the clip below.

   The group's own animation is deliberately left alone. It is a no-op for the
   root -- old and new occupy the same box -- and leaving it in place means the
   transition always has at least one animation of its own, so it cannot decide
   it has nothing to wait for before the clip below is attached. */
::view-transition-image-pair(root) {
    isolation: auto;
}

::view-transition-old(root),
::view-transition-new(root) {
    animation: none;
    mix-blend-mode: normal;
}

::view-transition-old(root) {
    z-index: 0;
}

/* The new snapshot starts fully clipped HERE, in CSS, not from JS. The
   pseudo-elements exist before the transition's ready promise resolves, so a
   clip applied out of that promise lands at least one frame late -- and that
   one frame is the entire new theme at full size, which is the flash this
   whole effect exists to avoid. */
::view-transition-new(root) {
    z-index: 1;
    clip-path: circle(0px at ${point.x}px ${point.y}px);
}
`
}

/* ------------------------------------------------------------------------- */
/* Handing the flip back to Filament                                          */
/* ------------------------------------------------------------------------- */

/**
 * Re-dispatch the event this module intercepted, so Filament's own listener
 * performs the persistence and the store update it always would have.
 *
 * @param {?EventTarget} target
 * @param {string} detail
 */
function replay(target, detail) {
    const event = new CustomEvent('theme-changed', {
        detail,
        bubbles: true,
        composed: true,
        cancelable: true,
    })

    event[REPLAY] = true

    /* Sent from the element the switcher used, so it travels the exact path the
       original would have. Filament listens on window, but nothing guarantees it
       stays the only listener, and `stopImmediatePropagation()` denied the
       original event every node on that path. If the switcher has since been
       removed -- the user menu is inside a dropdown Livewire can re-render --
       window is the one target still guaranteed to reach the vendor handler. */
    const from = target && target.isConnected ? target : window

    try {
        from.dispatchEvent(event)
    } catch (error) {
        console.warn('[vpn-forge motion] theme replay failed', error)
    }

    /* Net for having cut the original event off. Filament's handler runs
       synchronously during the dispatch above, so by now the choice is normally
       already stored and this does nothing. If it is not -- Alpine never
       initialised, the vendor script changed -- the preference would silently
       revert on the next reload, and a toggle that does not stick is a bug this
       module introduced. */
    try {
        if (window.localStorage && window.localStorage.getItem('theme') !== detail) {
            window.localStorage.setItem('theme', detail)
        }
    } catch (error) {
        // Storage can be denied outright (private mode, blocked cookies).
    }
}

/**
 * Finish a reveal left in flight, from wherever. Safe to call at any time and
 * as often as you like.
 */
function finishPending() {
    const pending = live

    if (!pending) {
        return
    }

    live = null

    try {
        pending.settle()
    } catch (error) {
        console.warn('[vpn-forge motion] theme cleanup failed', error)
    }
}

/** Remove every node this module has ever appended, including orphans from a
 *  run that was interrupted before its disposer got to them. */
function sweep() {
    document.querySelectorAll(`[${OWNED}]`).forEach((node) => node.remove())
}

/* ------------------------------------------------------------------------- */
/* Geometry and helpers                                                       */
/* ------------------------------------------------------------------------- */

/**
 * What `dark` will be after Filament handles this event, or null if the detail
 * is not one of the three values the switcher emits. Mirrors dark-mode.js.
 *
 * @param {*} detail
 * @returns {?boolean}
 */
function resolvesToDark(detail) {
    if (detail === 'dark') {
        return true
    }

    if (detail === 'light') {
        return false
    }

    if (detail === 'system') {
        return window.matchMedia('(prefers-color-scheme: dark)').matches
    }

    return null
}

/**
 * The point a click happened at, in viewport coordinates.
 *
 * @param {MouseEvent} event
 * @param {HTMLElement} button
 * @returns {?{x: number, y: number, at: number}}
 */
function pointOf(event, button) {
    const at = performance.now()

    // A keyboard activation reports (0, 0); the button's own centre is the
    // honest origin there, and it is still the button the operator pressed.
    if (event.clientX || event.clientY) {
        return { x: event.clientX, y: event.clientY, at }
    }

    return centreOf(button, at)
}

/**
 * Where the circle should open from. The recorded click if there is a fresh one,
 * then progressively vaguer stand-ins -- the reveal is worth doing from
 * approximately the right place, and it is never worth throwing away.
 *
 * @param {?{x: number, y: number, at: number}} recorded
 * @returns {{x: number, y: number}}
 */
function originPoint(recorded) {
    if (recorded && performance.now() - recorded.at < ORIGIN_TTL) {
        return recorded
    }

    const fallback =
        centreOf(document.querySelector('.fi-theme-switcher-btn.fi-active')) ??
        centreOf(document.querySelector('.fi-theme-switcher')) ??
        // The control that opens the menu the switcher lives in. Present even
        // when the dropdown panel itself has been collapsed away.
        centreOf(document.querySelector('.fi-user-menu-trigger'))

    if (fallback) {
        return fallback
    }

    // Last resort: the corner the user menu occupies.
    const inset = 28
    const rtl = document.documentElement.getAttribute('dir') === 'rtl'

    return { x: rtl ? inset : Math.max(inset, viewportWidth() - inset), y: inset }
}

/**
 * @param {?Element} element
 * @param {number} [at]
 * @returns {?{x: number, y: number, at: number}}
 */
function centreOf(element, at) {
    if (!element || !element.isConnected) {
        return null
    }

    const rect = element.getBoundingClientRect()

    // Zero-sized means hidden -- a collapsed dropdown panel, an `x-cloak`ed
    // node -- and its box is not a place on screen.
    if (!rect.width && !rect.height) {
        return null
    }

    return {
        x: rect.left + rect.width / 2,
        y: rect.top + rect.height / 2,
        at: at ?? performance.now(),
    }
}

/**
 * Radius that reaches the farthest viewport corner from (x, y), so the reveal
 * finishes covered no matter which corner the button sits in.
 *
 * @param {number} x
 * @param {number} y
 * @returns {number}
 */
function coverRadius(x, y) {
    const width = viewportWidth()
    const height = viewportHeight()

    // Rounded up with a couple of pixels of margin: a circle that stops exactly
    // on the corner leaves an antialiased sliver of the old theme there.
    return Math.ceil(Math.hypot(Math.max(x, width - x), Math.max(y, height - y))) + 2
}

function viewportWidth() {
    return window.innerWidth || document.documentElement.clientWidth || 0
}

function viewportHeight() {
    return window.innerHeight || document.documentElement.clientHeight || 0
}

/**
 * Run `fn` inside the runtime's gsap context so anything it creates is reverted
 * on the next navigation. Tweens born in a click handler have no context of
 * their own -- the runtime's context function returned long ago.
 *
 * The result is fished out through a closure rather than returned, because
 * `context.add()` treats a returned *function* as a cleanup callback and would
 * invoke it on revert.
 *
 * @param {?object} host
 * @param {Function} fn
 */
function owned(host, fn) {
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

/**
 * A bag of teardown callbacks. Every listener and every appended node in this
 * file goes through one, so nothing survives a context revert.
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
                    console.warn('[vpn-forge motion] theme cleanup failed', error)
                }
            }
        },
    }
}
