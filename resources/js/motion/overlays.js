/*
 * Overlays -- modals, dropdowns, notifications
 * --------------------------------------------
 * Everything in this file sits ON TOP of the page: a dialog that takes the
 * screen, a menu that grows out of the control you clicked, a toast that slides
 * in and counts itself down. Filament already shows and hides all three with
 * Alpine, and motion.css already gives them a duration and a spring. This module
 * is the layer above that -- the one that makes an overlay feel like it has
 * mass, a direction it came from, and somewhere it came from.
 *
 * Four decisions shape the whole file.
 *
 * 1. GSAP OWNS `transform`. NOTHING ELSE.
 *    motion.css states the house rule: the CSS layer displaces things with the
 *    individual `translate:` / `scale:` properties, GSAP writes `transform`, and
 *    the browser composes the two instead of letting them fight. That works only
 *    if no CSS *transition* is also re-easing `transform` behind us -- and both
 *    `.fi-dropdown-panel` and `.fi-no-notification` carry Tailwind's `transition`
 *    utility, whose property list includes it, while `.fi-modal-window` inherits
 *    the initial `transition-property: all`. So the injected stylesheet below
 *    restates `transition-property` for the surfaces we drive, minus `transform`
 *    and nothing else. `transition-duration` is deliberately NOT restated:
 *    Alpine reads it back off the computed style to time its own show/hide, and
 *    changing it would desynchronise Filament from itself.
 *
 * 2. OPACITY IS NEVER OURS ON A SURFACE THAT CAN BE LEFT BEHIND.
 *    The modal window, the dropdown panel and the toast are faded by Filament's
 *    own CSS. This module only ever moves them. That is what makes rule two of
 *    this panel -- nothing may be left unreadable -- structurally true rather
 *    than something a tween has to remember: if every tween in here were killed
 *    mid-flight, every overlay would still be fully opaque. The only opacity
 *    tweens are on a modal's three inner regions, they are all resolved inside
 *    0.6s, and a timed failsafe forces them open if one never lands.
 *
 * 3. TRANSFORMS ARE TRANSIENT. Filament renders modals inline -- a table action's
 *    modal lives inside the table, a dropdown item's modal inside the dropdown
 *    panel -- and a `position: fixed` element is positioned against its nearest
 *    transformed ancestor, not the viewport. A permanent transform on a modal
 *    window or a dropdown panel would therefore anchor any modal opened from
 *    inside it to that panel. Every tween in here clears its own transform when
 *    it lands. The one long-lived effect, a toast's countdown, is a custom
 *    property feeding a pseudo-element, so it holds no transform at all.
 *
 * 4. THE HOOKS ARE FILAMENT'S OWN, NOT GUESSES AT TIMING.
 *    Modals announce themselves: `filamentModal.open()` dispatches
 *    `x-modal-opened` on the document and `close()` dispatches `modal-closed`
 *    from the modal element (vendor/filament/support/resources/js/components/
 *    modal.js). Dropdowns are found through `aria-expanded`, which both
 *    alpine-floating-ui and Filament's own `syncAria()` keep truthful on every
 *    path including click-away. Toasts are created on demand by Livewire, so
 *    the notification container is watched with a MutationObserver -- the only
 *    thing that sees a node that did not exist a moment ago.
 *
 * Cleanup: gsap.context() reverts tweens, not observers or listeners. A nested
 * context is created here purely so its revert -- triggered by the runtime's --
 * runs the disposer that disconnects every observer, drops every listener,
 * removes the stylesheet and strips the attributes this file added.
 */

import { CustomEase } from 'gsap/CustomEase'

const STYLE_ID = 'vf-overlays'

/**
 * Marks a surface whose `transform` this module has taken over. The stylesheet
 * keys its `transition-property` override off this, so an overlay we never
 * reached keeps Filament's behaviour exactly. Swept on teardown.
 */
const OWNED = 'data-vf-overlay'

/** Marks a toast whose exit has already been started, so the two class mutations
 *  Alpine makes during a leave (`-leave-start`, then `-leave-end`) do not each
 *  fire one. */
const LEAVING = 'data-vf-overlay-out'

/**
 * The signature arrival curve: a damped oscillator, sampled as three bezier
 * segments. `back.out()` can only overshoot once and then stop, which reads as a
 * bounce; this overshoots ~3%, comes back under by a fraction, and settles --
 * the same shape as the `linear()` springs motion.css uses, so the JS and CSS
 * halves of an overlay's arrival move with one character.
 */
const ARRIVE = 'vfOverlayArrive'
const ARRIVE_PATH =
    'M0,0 C0.1,0 0.185,0.9 0.34,0.975 C0.46,1.031 0.58,1.014 0.7,1.003 C0.82,0.997 0.9,1 1,1'

/** Backdrop blur, in px, at full strength. One full-viewport backdrop-filter is
 *  the most expensive thing this whole motion layer asks for, and this panel
 *  runs on a 2-core box -- so it is modest, and only ramped on desktop. */
const BACKDROP_BLUR = 8

/** Fallback blur, matching the static value motion.css already ships, so a
 *  modal whose ramp never runs looks exactly as it did before. */
const BACKDROP_REST = 3

/** Hard caps. A dropdown with sixty items is a list, not a menu, and staggering
 *  all of it would still be arriving after the operator has chosen. */
const MAX_MENU_ITEMS = 14
const MAX_FOOTER_ACTIONS = 6

const CSS = `
/* Registered so the values are typed: GSAP reads the computed value back before
   it tweens, and an unregistered custom property reads as an empty string.
   Unitless on purpose -- the multiplication happens in calc() below, which keeps
   the tween a plain number tween with nothing to parse. */
@property --vf-modal-blur { syntax: '<number>'; inherits: false; initial-value: ${BACKDROP_REST}; }
@property --vf-modal-sat { syntax: '<number>'; inherits: false; initial-value: 1; }
@property --vf-toast-p { syntax: '<number>'; inherits: false; initial-value: 1; }

/* GSAP owns \`transform\` on the three surfaces below, so the CSS transition on
   them steps aside for that ONE property and keeps everything else. \`opacity\`
   in particular stays the stylesheet's, which is what guarantees an overlay can
   never be left invisible by a tween that did not finish. \`translate\`, \`scale\`
   and \`rotate\` stay too: motion.css displaces dropdowns and Filament shrinks a
   leaving toast with them, and both compose with our transform rather than
   fighting it.

   \`transition-duration\` is not restated anywhere in this file. Alpine reads it
   off the computed style to decide how long to wait before hiding an element. */
.fi-modal > .fi-modal-window-ctn > .fi-modal-window[${OWNED}],
.fi-dropdown-panel[${OWNED}],
.fi-no-notification[${OWNED}] {
    transition-property:
        color, background-color, border-color, outline-color, fill, stroke,
        opacity, box-shadow, translate, scale, rotate, filter, backdrop-filter;
}

/* The backdrop. motion.css already blurs it statically and lets Filament's own
   opacity fade carry the blur in for free; this makes the blur itself a ramp, so
   the page behind does not just dim, it recedes. The fallback in each var() is
   that static value, so the moment before the first frame is identical to what
   the stylesheet alone produces.

   Safe to filter: this element is empty. A backdrop-filter would otherwise make
   it the containing block for any fixed-position descendant, which is exactly
   the trap rule three of this file exists to avoid. */
.fi-modal > .fi-modal-close-overlay[${OWNED}] {
    -webkit-backdrop-filter:
        blur(calc(var(--vf-modal-blur, ${BACKDROP_REST}) * 1px))
        saturate(var(--vf-modal-sat, 1));
    backdrop-filter:
        blur(calc(var(--vf-modal-blur, ${BACKDROP_REST}) * 1px))
        saturate(var(--vf-modal-sat, 1));
}

/* --- Toast countdown -------------------------------------------------------
   A toast that disappears on its own should say so before it does. The bar is a
   pseudo-element rather than an inserted node: the notification is Livewire's
   markup and anything we put inside it would be in the path of the next morph.

   \`.fi-no-notification\` is already \`overflow: hidden\` with a rounded corner, so
   the bar is clipped to the card's own shape. \`position: relative\` is layout
   neutral here (the card is a flex row of static children) and, unlike a
   transform, creates no containing block for a fixed-position descendant -- a
   notification action can open a modal. */
.fi-no-notification[${OWNED}='timed'] {
    position: relative;
}

.fi-no-notification[${OWNED}='timed']::after {
    content: '';
    position: absolute;
    inset-inline: 0;
    bottom: 0;
    height: 2px;
    pointer-events: none;
    transform-origin: left center;
    transform: scaleX(var(--vf-toast-p, 1));
    /* The notification's own status colour -- Filament sets --color-* on every
       .fi-color-<name> -- falling back to the panel accent and then to a
       literal, so this cannot end up invisible if a palette is missing. */
    background-color: color-mix(
        in oklab,
        var(--color-500, var(--vf-accent, var(--primary-500, #f59e0b))) 70%,
        transparent
    );
}

[dir='rtl'] .fi-no-notification[${OWNED}='timed']::after {
    transform-origin: right center;
}
`

/**
 * Modals, dropdowns and notifications.
 *
 * @param {{gsap: import('gsap').gsap, ScrollTrigger: object, MOTION: object}} api
 */
export function overlays({ gsap, MOTION }) {
    if (typeof document === 'undefined' || !document.body) {
        return null
    }

    gsap.registerPlugin(CustomEase)

    // Idempotent: re-creating an ease under a name it already holds just
    // replaces it, which is what we want on every navigation.
    CustomEase.create(ARRIVE, ARRIVE_PATH)

    const clamp = gsap.utils.clamp

    /*
     * `gsap.context()` with no argument returns the context we are running
     * inside -- the runtime's. Every tween in this file is created later, from
     * an observer or an event handler, and would otherwise be born with no
     * context at all: nothing would revert it, and the inline transform it left
     * behind would survive the next navigation frozen mid-animation. Routing
     * them through `add()` puts them back under the runtime's control.
     */
    const host = gsap.context()

    /**
     * Runs `fn` as part of the runtime's context and hands back its result. The
     * result comes out through a closure rather than a return because
     * `context.add()` treats a returned *function* as a cleanup callback.
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

    // gsap.context() reverts tweens, not observers. This nested context exists
    // only so the runtime's revert() reaches the disposer below.
    gsap.context(() => () => scope.dispose())

    injectStylesheet(scope)

    modalMotion({ gsap, MOTION, scope, owned })
    dropdownMotion({ gsap, MOTION, clamp, scope, owned })
    toastMotion({ gsap, MOTION, scope, owned })

    return null
}

/* ------------------------------------------------------------------------- */
/* Lifecycle helpers                                                          */
/* ------------------------------------------------------------------------- */

/**
 * A bag of teardown callbacks. Every listener, observer, animation frame and
 * DOM insertion in this file goes through one of these, so nothing survives a
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
        observe(observer, target, options) {
            if (!observer || !target) {
                return
            }

            observer.observe(target, options)
            disposers.push(() => observer.disconnect())
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
                    console.warn('[vpn-forge motion] overlays cleanup failed', error)
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

    // The attributes are inert once the stylesheet above is gone, but a stale
    // one would make the next run think a surface had already been handled --
    // and the custom properties would keep a half-depleted countdown bar drawn
    // on a toast nobody is timing any more.
    scope.add(() => {
        document.querySelectorAll(`[${OWNED}], [${LEAVING}]`).forEach((element) => {
            element.removeAttribute(OWNED)
            element.removeAttribute(LEAVING)
            element.style.removeProperty('--vf-modal-blur')
            element.style.removeProperty('--vf-modal-sat')
            element.style.removeProperty('--vf-toast-p')
        })
    })
}

/**
 * Animation frames that have not run yet, so a teardown mid-flight does not
 * leave a callback holding a node from the previous page.
 */
function createFrames(scope) {
    const pending = new Set()

    scope.add(() => {
        pending.forEach((id) => cancelAnimationFrame(id))
        pending.clear()
    })

    return (callback) => {
        const id = requestAnimationFrame(() => {
            pending.delete(id)
            callback()
        })

        pending.add(id)
    }
}

/**
 * Blur is the one effect in this module that costs real GPU time, and this panel
 * runs on modest hardware. Read per modal rather than once, so a window resized
 * across the breakpoint gets the right answer.
 */
function wantsBackdropRamp() {
    return window.matchMedia('(min-width: 64rem)').matches
}

/**
 * Insurance, not choreography.
 *
 * If a tween is interrupted -- a modal closed and reopened inside its own
 * entrance, content Livewire swapped underneath it -- this forces the element
 * open. The inline opacity is deliberately NOT cleared afterwards: this path
 * only runs when something already went wrong, and a value an operator can read
 * is worth more than a clean style attribute.
 */
function forceReadable(gsap, nodes) {
    nodes.forEach((node) => {
        if (!node || !node.isConnected) {
            return
        }

        if (Number(gsap.getProperty(node, 'opacity')) >= 0.99) {
            return
        }

        gsap.to(node, {
            opacity: 1,
            duration: 0.2,
            ease: 'power3.out',
            overwrite: true,
            clearProps: 'transform,transformOrigin,willChange',
        })
    })
}

/** Direct children of `parent` matching each selector, in the order given. */
function regionsOf(parent, selectors) {
    const found = []

    selectors.forEach((selector) => {
        const node = parent.querySelector(`:scope > ${selector}`)

        if (node) {
            found.push(node)
        }
    })

    return found
}

/* ------------------------------------------------------------------------- */
/* Modals                                                                     */
/* ------------------------------------------------------------------------- */

/**
 * A modal arrives as three things happening at once: the page behind it recedes
 * and defocuses, the window rises onto a spring, and the window's own regions
 * settle inside it a beat later. It leaves in a third of the time, because by
 * then you have already decided.
 *
 * Both hooks are Filament's. `x-modal-opened` is dispatched on the document from
 * `filamentModal.open()`; `modal-closed` bubbles from the modal element out of
 * `close()`. Neither fires for a modal that never opens, which is most of them
 * -- a table page renders one per row action.
 */
function modalMotion({ gsap, MOTION, scope, owned }) {
    const nextFrame = createFrames(scope)

    const resolve = (id) => {
        if (id) {
            // Filament modal ids contain dots (`component.actions.create`), so
            // getElementById rather than a selector that would need escaping.
            const byId = document.getElementById(id)

            if (byId && byId.classList.contains('fi-modal')) {
                return byId
            }
        }

        // A modal declared without an id has none to announce. The topmost open
        // one is the one that just opened -- the same assumption Filament's own
        // `isTopmost()` makes.
        const open = document.querySelectorAll('.fi-modal.fi-modal-open')

        return open.length ? open[open.length - 1] : null
    }

    const partsOf = (win) =>
        regionsOf(win, ['.fi-modal-header', '.fi-modal-content', '.fi-modal-footer'])

    const enter = (id) => {
        const modal = resolve(id)

        if (!modal) {
            return
        }

        const win = modal.querySelector(':scope > .fi-modal-window-ctn > .fi-modal-window')
        const overlay = modal.querySelector(':scope > .fi-modal-close-overlay')

        if (overlay) {
            overlay.setAttribute(OWNED, '')

            const ramp = wantsBackdropRamp()

            owned(() =>
                gsap.fromTo(
                    overlay,
                    { scale: 1.06, '--vf-modal-blur': 0, '--vf-modal-sat': 1 },
                    {
                        // The backdrop settles back to rest while it defocuses,
                        // so the page reads as pushed away rather than covered.
                        scale: 1,
                        '--vf-modal-blur': ramp ? BACKDROP_BLUR : BACKDROP_REST,
                        '--vf-modal-sat': ramp ? 1.06 : 1,
                        duration: MOTION.slow * 0.5,
                        ease: MOTION.easeSoft,
                        overwrite: 'auto',
                        clearProps: 'transform,transformOrigin',
                    },
                ),
            )
        }

        if (!win) {
            return
        }

        win.setAttribute(OWNED, '')

        // A slide-over is already travelling: Filament moves it a full window
        // width with `translate:`, and adding a rise to that reads as two
        // different animations disagreeing about where the panel came from. It
        // gets the inner choreography only.
        if (!modal.classList.contains('fi-modal-slide-over')) {
            owned(() =>
                gsap.fromTo(
                    win,
                    { y: 26, scale: 0.965, transformOrigin: '50% 35%' },
                    {
                        y: 0,
                        scale: 1,
                        duration: MOTION.base * 1.05,
                        ease: ARRIVE,
                        overwrite: 'auto',
                        // Cleared the moment it lands: a modal window holding a
                        // transform is the containing block for any nested
                        // modal's fixed-position wrapper.
                        clearProps: 'transform,transformOrigin,willChange',
                    },
                ),
            )
        }

        const parts = partsOf(win)

        if (parts.length) {
            owned(() =>
                gsap.fromTo(
                    parts,
                    { y: 16, opacity: 0 },
                    {
                        y: 0,
                        opacity: 1,
                        // The only opacity this module takes from the
                        // stylesheet, and the shortest ramp in the file: the
                        // last region is fully readable 0.58s after the modal
                        // was asked for.
                        duration: MOTION.fast * 1.3,
                        ease: MOTION.ease,
                        stagger: 0.07,
                        delay: 0.08,
                        overwrite: 'auto',
                        clearProps: 'transform,opacity,willChange',
                    },
                ),
            )
        }

        // The icon is the modal's one piece of pure signal -- a danger triangle
        // on a destructive confirmation -- so it gets the spring.
        const icon = win.querySelector(':scope > .fi-modal-header .fi-modal-icon-bg')

        if (icon) {
            owned(() =>
                gsap.fromTo(
                    icon,
                    { scale: 0.55 },
                    {
                        scale: 1,
                        duration: MOTION.base,
                        ease: MOTION.spring,
                        delay: 0.14,
                        overwrite: 'auto',
                        clearProps: 'transform,transformOrigin',
                    },
                ),
            )
        }

        const close = win.querySelector(':scope > .fi-modal-header > .fi-modal-close-btn')

        if (close) {
            owned(() =>
                gsap.fromTo(
                    close,
                    { scale: 0.4, rotate: -90 },
                    {
                        scale: 1,
                        rotate: 0,
                        duration: MOTION.base,
                        ease: MOTION.spring,
                        delay: 0.2,
                        overwrite: 'auto',
                        clearProps: 'transform,transformOrigin',
                    },
                ),
            )
        }

        // Footer actions deal in from the side, after everything else has
        // arrived -- the last thing to settle is the thing you are being asked
        // to press. Transform only: these must never be dimmed, because one of
        // them is usually the way out of the dialog.
        const footer = win.querySelector(':scope > .fi-modal-footer')
        const actions = footer
            ? gsap.utils
                  .toArray(footer.querySelectorAll(':scope > .fi-modal-footer-actions > *'))
                  .slice(0, MAX_FOOTER_ACTIONS)
            : []

        if (actions.length) {
            const away = document.dir === 'rtl' ? -10 : 10

            owned(() =>
                gsap.from(actions, {
                    x: away,
                    duration: MOTION.fast * 1.4,
                    ease: MOTION.ease,
                    stagger: 0.05,
                    delay: 0.22,
                    overwrite: 'auto',
                    clearProps: 'transform',
                }),
            )
        }

        // The failsafe. Everything above is resolved by ~0.6s; if the modal is
        // still open at 1.1s with a region under full opacity, something
        // interrupted a tween and the operator is looking at a dialog they
        // cannot read.
        owned(() =>
            gsap.delayedCall(1.1, () => {
                if (!modal.isConnected || !modal.classList.contains('fi-modal-open')) {
                    return
                }

                // Routed through owned() again: the delayedCall itself belongs
                // to the runtime's context, but a tween created from inside its
                // callback would be born outside it.
                owned(() => forceReadable(gsap, partsOf(win)))
            }),
        )
    }

    const leave = (event) => {
        const modal = event && event.target

        if (!modal || modal.nodeType !== 1 || !modal.classList.contains('fi-modal')) {
            return
        }

        const win = modal.querySelector(':scope > .fi-modal-window-ctn > .fi-modal-window')
        const overlay = modal.querySelector(':scope > .fi-modal-close-overlay')

        // 160ms against the 620ms it took to arrive. motion.css gives the
        // window's own CSS leave 180ms, so this lands just inside it and the
        // cleared transform hands straight over to a state that is already
        // transparent -- nothing flashes back.
        if (win && !modal.classList.contains('fi-modal-slide-over')) {
            owned(() =>
                gsap.to(win, {
                    y: -10,
                    scale: 0.98,
                    duration: 0.16,
                    ease: 'power2.in',
                    overwrite: 'auto',
                    transformOrigin: '50% 35%',
                    clearProps: 'transform,transformOrigin,willChange',
                }),
            )
        }

        if (overlay) {
            owned(() =>
                gsap.to(overlay, {
                    scale: 1.03,
                    '--vf-modal-blur': 0,
                    '--vf-modal-sat': 1,
                    duration: 0.18,
                    ease: 'power2.in',
                    overwrite: 'auto',
                    onComplete: () => {
                        gsap.set(overlay, { clearProps: 'transform,transformOrigin' })
                        // clearProps is unreliable for custom properties, and a
                        // blur left declared on a hidden overlay is a filter the
                        // compositor keeps honouring.
                        overlay.style.removeProperty('--vf-modal-blur')
                        overlay.style.removeProperty('--vf-modal-sat')
                    },
                }),
            )
        }
    }

    // One frame of delay, deliberately: `open()` sets `isOpen` and dispatches in
    // the same tick, so at dispatch time Alpine has not yet flushed the class
    // binding that marks the modal open, nor started the window's own show.
    scope.on(document, 'x-modal-opened', (event) => {
        const id = event && event.detail ? event.detail.id : null

        nextFrame(() => enter(id))
    })

    scope.on(document, 'modal-closed', leave)

    // A navigation cancels whatever was on screen. Filament's own dropdown
    // component does the same on `livewire:navigate`.
    scope.on(document, 'livewire:navigating', () => {
        document.querySelectorAll(`.fi-modal-close-overlay[${OWNED}]`).forEach((overlay) => {
            overlay.style.removeProperty('--vf-modal-blur')
            overlay.style.removeProperty('--vf-modal-sat')
        })
    })
}

/* ------------------------------------------------------------------------- */
/* Dropdowns and the user menu                                                */
/* ------------------------------------------------------------------------- */

/**
 * A menu grows out of the control that opened it.
 *
 * That is the whole idea: the transform origin is placed on the trigger's centre
 * as measured inside the panel's own box, so a menu hanging below-right of a
 * button expands from its top-left corner, and the user menu in the top bar --
 * which Filament teleports to the body and floats bottom-end -- expands from its
 * top-right. motion.css already gives every panel a generic rise from
 * `top center`; this is the layer that knows which control you actually pressed.
 *
 * `aria-expanded` is the hook. alpine-floating-ui writes it on open and close,
 * and Filament's `syncAria()` re-writes it from its own MutationObserver on the
 * panel's `style` -- which means it stays truthful even when the panel is closed
 * by a click-away or the plugin's own Escape handler, neither of which routes
 * through the component. One attribute filter on the body covers every dropdown
 * on the page, including ones rendered after this ran.
 */
function dropdownMotion({ gsap, MOTION, clamp, scope, owned }) {
    const nextFrame = createFrames(scope)

    // Both the focusable trigger and the wrapper around it end up carrying
    // `aria-expanded` -- the plugin writes one, Filament moves it to the other
    // -- so an open produces two mutations. State is tracked per panel instead.
    const open = new WeakSet()

    const panelFor = (element) => {
        const controls = element.getAttribute('aria-controls')

        if (controls) {
            // The generated panel id is `fi-dropdown-panel-<random>`, and this
            // is the only route that survives `x-float.teleport` moving the
            // panel out of its dropdown and onto the body.
            const byId = document.getElementById(controls)

            if (byId && byId.classList.contains('fi-dropdown-panel')) {
                return byId
            }
        }

        const root = element.closest ? element.closest('.fi-dropdown') : null

        return root ? root.querySelector(':scope > .fi-dropdown-panel') : null
    }

    /**
     * The trigger's centre, expressed as a percentage of the panel's own box.
     * Clamped, because a flipped or shifted panel can end up entirely to one
     * side of its trigger and an origin outside the box would swing the panel
     * through the screen on its way in.
     */
    const cornerOrigin = (panel, trigger) => {
        const box = panel.getBoundingClientRect()
        const from = trigger.getBoundingClientRect()

        if (!box.width || !box.height || !from.width) {
            return '50% 0%'
        }

        const x = clamp(0, 100, ((from.left + from.width / 2 - box.left) / box.width) * 100)
        const y = clamp(0, 100, ((from.top + from.height / 2 - box.top) / box.height) * 100)

        return `${x.toFixed(1)}% ${y.toFixed(1)}%`
    }

    /**
     * Floating UI positions the panel from a promise, so `left`/`top` land a
     * tick after the panel is shown. Measuring before that would put the origin
     * on wherever the panel was last frame. Up to three frames, then give up and
     * animate anyway -- a slightly wrong origin is better than no motion.
     */
    const whenPositioned = (panel, run, tries) => {
        nextFrame(() => {
            if (!panel.isConnected) {
                return
            }

            if (tries > 0 && (!panel.style.top || !panel.offsetWidth)) {
                whenPositioned(panel, run, tries - 1)

                return
            }

            run()
        })
    }

    const show = (panel, trigger) => {
        if (open.has(panel)) {
            return
        }

        open.add(panel)
        panel.setAttribute(OWNED, '')

        whenPositioned(
            panel,
            () => {
                // Closed again inside those three frames -- a double click on
                // the trigger will do it.
                if (!open.has(panel) || !panel.isConnected) {
                    return
                }

                const origin = cornerOrigin(panel, trigger)

                owned(() => {
                    // Set, not tweened: the origin has to be in place before the
                    // first frame of the scale or the panel starts from its
                    // middle and slides across to the corner.
                    gsap.set(panel, { transformOrigin: origin, willChange: 'transform' })

                    return gsap.fromTo(
                        panel,
                        { scale: 0.9 },
                        {
                            scale: 1,
                            duration: MOTION.base * 0.62,
                            ease: ARRIVE,
                            overwrite: 'auto',
                            // transformOrigin is deliberately kept: the exit
                            // collapses back into the same corner, and it is
                            // cleared there. An origin holds no transform, so it
                            // creates no containing block for the modals a
                            // dropdown item can open.
                            clearProps: 'transform,willChange',
                        },
                    )
                })

                const items = gsap.utils
                    .toArray(panel.querySelectorAll('.fi-dropdown-list-item, .fi-dropdown-header'))
                    .slice(0, MAX_MENU_ITEMS)

                if (!items.length) {
                    return
                }

                // Transform only. The panel's own fade is Filament's, and a menu
                // item that is still fading up is a menu item you cannot click
                // yet -- these are ready the instant the panel is.
                const away = document.dir === 'rtl' ? -8 : 8

                owned(() =>
                    gsap.from(items, {
                        x: away,
                        duration: MOTION.fast * 1.2,
                        ease: MOTION.ease,
                        stagger: 0.022,
                        delay: 0.04,
                        overwrite: 'auto',
                        clearProps: 'transform',
                    }),
                )
            },
            3,
        )
    }

    const hide = (panel) => {
        // Never opened by us -- Filament writes `aria-expanded="false"` once on
        // every dropdown at init, and a page can carry dozens.
        if (!open.has(panel)) {
            return
        }

        open.delete(panel)

        owned(() =>
            gsap.to(panel, {
                scale: 0.96,
                duration: 0.13,
                ease: 'power2.in',
                overwrite: 'auto',
                clearProps: 'transform,transformOrigin,willChange',
            }),
        )
    }

    const observer = new MutationObserver((records) => {
        for (let i = 0; i < records.length; i += 1) {
            const element = records[i].target

            if (!element || element.nodeType !== 1) {
                continue
            }

            const state = element.getAttribute('aria-expanded')

            // A REMOVED attribute reads as null, and Filament removes it from
            // the wrapper as part of moving it onto the real control. Treating
            // that as a close would fire an exit over the entrance.
            if (state !== 'true' && state !== 'false') {
                continue
            }

            const panel = panelFor(element)

            // Everything else on the page that carries aria-expanded -- selects,
            // disclosure buttons, the sidebar's group toggles -- resolves to
            // nothing here and is left alone.
            if (!panel) {
                continue
            }

            if (state === 'true') {
                show(panel, element)
            } else {
                hide(panel)
            }
        }
    })

    scope.observe(observer, document.body, {
        subtree: true,
        attributes: true,
        attributeFilter: ['aria-expanded'],
    })
}

/* ------------------------------------------------------------------------- */
/* Notifications                                                              */
/* ------------------------------------------------------------------------- */

/**
 * Toasts arrive from the edge they are pinned to, stacked with an overlap, and
 * count themselves down.
 *
 * Livewire creates them on demand, so a MutationObserver on the container is the
 * only thing that sees them. The same observer watches for the transition
 * classes Alpine puts on a leaving toast, which is how a dismissal gets an exit
 * that goes back the way it came instead of just fading in place.
 *
 * Opacity is untouched throughout -- Filament fades a toast in over 300ms and
 * out again, and this module only ever moves it. A toast is therefore readable
 * on exactly the schedule Filament intends, whatever happens to our tweens.
 */
function toastMotion({ gsap, MOTION, scope, owned }) {
    const container = document.querySelector('.fi-no')

    if (!container) {
        return
    }

    const vector = entryVector(container)

    /**
     * How long Filament will leave this toast on screen. The value is in the
     * Alpine component's own `x-data` expression -- `notificationComponent({
     * notification: {"id":"...","duration":6000} })` -- which is the only place
     * it exists in the DOM. A persistent notification has `"persistent"` there
     * and matches nothing, so it gets no countdown, which is correct: there is
     * nothing to count down to.
     */
    const timeoutOf = (toast) => {
        const expression = toast.getAttribute('x-data') || ''
        const match = expression.match(/"duration"\s*:\s*(\d+)/)

        if (!match) {
            return 0
        }

        const ms = Number(match[1])

        // Sanity bounds. Anything under a second is not worth drawing a bar for,
        // and anything over ten minutes is persistent in all but name.
        return ms >= 1000 && ms <= 600000 ? ms : 0
    }

    const arrive = (toasts) => {
        let index = 0

        toasts.forEach((toast) => {
            if (toast.hasAttribute(OWNED)) {
                return
            }

            // A toast already on its way out when this module (re)ran. Sliding
            // it back in would be the panel contradicting itself.
            if (
                toast.classList.contains('fi-transition-leave-start') ||
                toast.classList.contains('fi-transition-leave-end')
            ) {
                return
            }

            const ms = timeoutOf(toast)

            toast.setAttribute(OWNED, ms ? 'timed' : '')

            // Slight overlap rather than a queue: a burst of three toasts should
            // read as one gesture with depth, not as three separate arrivals.
            const delay = index * 0.075
            index += 1

            const from = { scale: 0.92, transformOrigin: vector.origin }
            const to = {
                scale: 1,
                duration: MOTION.base,
                ease: ARRIVE,
                delay,
                overwrite: 'auto',
                clearProps: 'transform,transformOrigin,willChange',
            }

            from[vector.axis] = vector.travel
            to[vector.axis] = 0

            owned(() => gsap.fromTo(toast, from, to))

            const icon = toast.querySelector(':scope > .fi-no-notification-icon')

            if (icon) {
                owned(() =>
                    gsap.fromTo(
                        icon,
                        { scale: 0.4, rotate: -20 },
                        {
                            scale: 1,
                            rotate: 0,
                            duration: MOTION.base * 1.15,
                            ease: MOTION.spring,
                            delay: delay + 0.09,
                            overwrite: 'auto',
                            clearProps: 'transform,transformOrigin',
                        },
                    ),
                )
            }

            const text = gsap.utils.toArray(
                toast.querySelectorAll(
                    '.fi-no-notification-title, .fi-no-notification-date, .fi-no-notification-body, .fi-no-notification-actions',
                ),
            )

            if (text.length) {
                owned(() =>
                    gsap.from(text, {
                        x: vector.axis === 'x' && vector.travel < 0 ? -12 : 12,
                        duration: MOTION.fast * 1.5,
                        ease: MOTION.ease,
                        stagger: 0.045,
                        delay: delay + 0.07,
                        overwrite: 'auto',
                        clearProps: 'transform',
                    }),
                )
            }

            if (!ms) {
                return
            }

            // Undelayed on purpose: Filament's own timer starts the moment the
            // component initialises, so a bar that waited for the stagger would
            // be telling a small lie about when the toast disappears. It rests
            // at zero if the pointer is over the card, which is exactly what
            // Filament does -- the time is up, it is waiting for you to leave.
            owned(() =>
                gsap.fromTo(
                    toast,
                    { '--vf-toast-p': 1 },
                    { '--vf-toast-p': 0, duration: ms / 1000, ease: 'none', overwrite: 'auto' },
                ),
            )
        })
    }

    const depart = (toast) => {
        if (toast.hasAttribute(LEAVING)) {
            return
        }

        toast.setAttribute(LEAVING, '')

        const to = {
            scale: 0.88,
            duration: 0.2,
            ease: 'power2.in',
            overwrite: 'auto',
            transformOrigin: vector.origin,
        }

        // Back out the way it came in, a third faster.
        to[vector.axis] = vector.travel

        owned(() => {
            // Takes the countdown with it -- a bar still depleting on a card
            // that is sliding off screen is motion arguing with itself.
            gsap.killTweensOf(toast)

            return gsap.to(toast, to)
        })
    }

    const observer = new MutationObserver((records) => {
        const arrived = []

        for (let i = 0; i < records.length; i += 1) {
            const record = records[i]

            if (record.type === 'childList') {
                const added = record.addedNodes

                for (let j = 0; j < added.length; j += 1) {
                    const node = added[j]

                    if (!node || node.nodeType !== 1 || !node.classList) {
                        continue
                    }

                    // `fi-inline` is a stored notification rendered into the
                    // database-notifications list. It is a row in a list, not a
                    // toast, and Filament removes it without animation.
                    if (
                        node.classList.contains('fi-no-notification') &&
                        !node.classList.contains('fi-inline')
                    ) {
                        arrived.push(node)
                    }
                }

                continue
            }

            const target = record.target

            if (!target || target.nodeType !== 1 || !target.classList) {
                continue
            }

            if (!target.classList.contains('fi-no-notification')) {
                continue
            }

            // Alpine adds `-leave-start`, then swaps it for `-leave-end` a frame
            // later. Either one means the same thing, and LEAVING makes sure
            // only the first is acted on.
            if (
                target.classList.contains('fi-transition-leave-start') ||
                target.classList.contains('fi-transition-leave-end')
            ) {
                depart(target)
            }
        }

        if (arrived.length) {
            arrive(arrived)
        }
    })

    scope.observe(observer, container, {
        childList: true,
        subtree: true,
        attributes: true,
        attributeFilter: ['class'],
    })

    // A toast can already be on screen when this module runs -- a redirect that
    // carries a flash notification renders one into the first paint, and no
    // mutation will ever announce it.
    const standing = gsap.utils.toArray(
        container.querySelectorAll(':scope > .fi-no-notification:not(.fi-inline)'),
    )

    if (standing.length) {
        arrive(standing)
    }
}

/**
 * Which edge a toast comes from, decided by the container's own alignment
 * classes rather than by measuring.
 *
 * Measuring would be the obvious way and is the wrong one: a toast is inserted
 * by Livewire and initialised by Alpine in an order this observer cannot depend
 * on, and Alpine's first pass sets `display: none` before showing it -- so a
 * `getBoundingClientRect()` taken at insertion time is sometimes all zeros. The
 * classes are on the server-rendered container and are always right.
 */
function entryVector(container) {
    const classes = container.classList
    const rtl = document.dir === 'rtl'

    if (classes.contains('fi-align-center')) {
        // A centred stack has no side to come from, so it rises out of the edge
        // it is pinned to.
        const fromTop = classes.contains('fi-vertical-align-start')

        return {
            axis: 'y',
            travel: fromTop ? -26 : 26,
            origin: fromTop ? '50% 0%' : '50% 100%',
        }
    }

    // `start`/`end` are writing-mode relative, `left`/`right` are not. Filament
    // defaults to `Alignment::Right`, which is the branch this falls through to.
    let fromRight = true

    if (classes.contains('fi-align-left')) {
        fromRight = false
    } else if (classes.contains('fi-align-start')) {
        fromRight = rtl
    } else if (classes.contains('fi-align-end')) {
        fromRight = !rtl
    }

    return {
        axis: 'x',
        travel: fromRight ? 64 : -64,
        origin: fromRight ? '100% 50%' : '0% 50%',
    }
}
