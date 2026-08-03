/*
 * Form choreography
 * -----------------
 * The forms in this panel configure real VPN services -- an OpenVPN cipher, a
 * DNS upstream, a blocklist that either resolves or takes a customer offline.
 * So every effect in here is small, and every one of them is a REPLY: the panel
 * answering something the operator just did. Nothing animates on its own, and
 * nothing sits between a keystroke and the character appearing.
 *
 * What it covers:
 *   - focus: the field you are in pulses up and its label leans in, while the
 *     fields around it recede a shade so the eye lands
 *   - validation: the error unrolls from under the field, which shakes once, 3px
 *   - toggles, checkboxes and radios: the knob squashes, the box springs
 *   - tags, multi-select values, select options: insertion and removal
 *   - repeater and builder items: an item opens, and the list closes over one
 *     that was deleted
 *   - wizard steps: the panel arrives from the direction you are travelling
 *
 * Five constraints shape all of it.
 *
 * 1. NOTHING IS EVER LEFT UNREADABLE. The only opacity this module holds below
 *    1 is 0.86 on the sibling fields of the one you are editing -- perfectly
 *    readable on its own -- and it is restored on blur, on navigation, on
 *    teardown, and by a watchdog if none of those fire. Every one-shot reveal
 *    that starts at opacity 0 has a timed sweep behind it that forces it back.
 *
 * 2. TRANSFORMS ARE TRANSIENT. Filament renders action modals (`position:
 *    fixed`) inline, inside the section, repeater item or field that owns them,
 *    and an element holding a live transform becomes the containing block for
 *    its fixed descendants -- which would anchor a modal to a text input.
 *    Everything here that moves a node containing controls is a pulse that
 *    clears its own inline transform the moment it lands. Exactly one transform
 *    is HELD: the 2px lean on `.fi-fo-field-label-content`, a span the field
 *    wrapper builds out of the label text alone -- and it is skipped anyway if
 *    a control is ever found inside one.
 *
 * 3. LAYOUT IS NEVER ANIMATED. The "height collapse" of a deleted repeater item
 *    is the surviving items being FLIPped from where they used to be -- one
 *    transform each, on the compositor -- rather than a height tween that would
 *    reflow the whole form once a frame. Same reasoning for the error message:
 *    it unrolls with clip-path, so the fields below it are not re-laid-out
 *    twenty times on the way down.
 *
 * 4. NOTHING LISTENS PER ELEMENT. Fields are re-rendered constantly (Livewire
 *    morphs, Alpine `x-for`, modals mounting late), so all of this is delegated
 *    from `document` plus one MutationObserver. Nothing can go stale, and
 *    nothing can accumulate one dead closure per replaced node.
 *
 * 5. CSS AND GSAP NEVER DRIVE THE SAME PROPERTY. Tailwind 4's `transition`
 *    utility covers `transform` as well as `translate`, so anything GSAP takes
 *    over has its transition list narrowed for the length of the tween and
 *    handed straight back. The toggle knob is the interesting case: its slide is
 *    a CSS `translate` transition that has to keep working while GSAP owns
 *    `transform` for the squash, so that one is narrowed rather than switched
 *    off.
 *
 * Every class name below was read out of vendor/filament -- the field wrapper in
 * forms/resources/views/components/field-wrapper.blade.php, the rest from the
 * component CSS (forms/resources/css/components/*.css,
 * support/resources/css/components/**) and the embedded views in
 * forms/src/Components/*.php and schemas/src/Components/*.php.
 */

import { Flip } from 'gsap/Flip'

/* Marks an element whose inline styles or attributes this module wrote. Cleared
   wholesale at the start of every run and on teardown -- gsap.context() reverts
   tweens but knows nothing about attributes we set. */
const TOUCHED = 'data-vf-form'

/* An error message that has already had its reveal. Errors arrive both through
   the MutationObserver and through Filament's `form-validation-error` event, and
   without this the two would animate the same node twice. */
const SEEN = 'data-vf-form-seen'

/* How far a sibling field recedes while another one has focus. Chosen to be the
   most that is still unambiguously readable: this is a panel where a mistyped
   port number costs a support call. */
const RECEDE = 0.86

/* Ceilings. A form with more fields than this is a data-entry page, not a
   composition, and dimming forty things to highlight one costs more than it
   says. */
const MAX_RECEDE = 24
const MAX_STAGGER = 14
const MAX_BATCH = 120

/* Longest a single reveal may take before the failsafe sweep forces it visible
   regardless of what the tween did. */
const FAILSAFE = 1.3

/* Everything the mutation pass cares about. One selector, so each added node
   costs one `matches` and at most one `querySelectorAll`. */
const INTERESTING = [
    '[data-validation-error]',
    '.fi-badge',
    '.fi-fo-repeater-item',
    '.fi-fo-builder-item',
    '.fi-select-input-option',
].join(', ')

/* Flex containers of chips: tag inputs and the value badges of a multi-select.
   Both add and remove their children through Alpine, not Livewire. */
const CHIP_CTN = '.fi-fo-tags-input-tags-ctn, .fi-select-input-value-badges-ctn'

/* Vertical lists whose items Livewire adds and removes. */
const LIST_ITEM = '.fi-fo-repeater-item, .fi-fo-builder-item'

/* Containers whose children may be FLIPped closed after a deletion. */
const SETTLE_CTN = `${CHIP_CTN}, .fi-fo-repeater-items, .fi-fo-builder-items`

/*
 * Survives the runtime's teardown/boot cycle. Deferred work (failsafe sweeps,
 * coalesced mutation passes) captures the boot it belongs to; a callback from a
 * previous run writing to an element the current run owns would fight it.
 */
let generation = 0

/**
 * Form choreography.
 *
 * @param {{gsap: import('gsap').gsap, ScrollTrigger: object, MOTION: object}} api
 */
export function forms({ gsap, MOTION }) {
    if (typeof document === 'undefined' || !document.body) {
        return null
    }

    gsap.registerPlugin(Flip)

    const boot = ++generation

    // Anything a previous run marked and did not get to unmark -- an interrupted
    // navigation, a reduced-motion toggle mid-tween. Cheap, and it means a
    // stale attribute can never suppress an animation forever.
    document.querySelectorAll(`[${TOUCHED}], [${SEEN}]`).forEach((element) => {
        element.removeAttribute(TOUCHED)
        element.removeAttribute(SEEN)
    })

    /*
     * `gsap.context()` with no argument hands back the context we are currently
     * running inside -- the runtime's. Tweens created later, from an event
     * handler, would otherwise be born with no context at all: nothing would
     * revert them, and the inline transforms they left behind would survive the
     * next navigation frozen mid-animation.
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

    // gsap.context() reverts tweens, not listeners, observers or attributes.
    // This nested context exists only so the runtime's revert() reaches the
    // disposer below.
    gsap.context(() => () => scope.dispose())

    scope.add(() => {
        document.querySelectorAll(`[${TOUCHED}], [${SEEN}]`).forEach((element) => {
            element.removeAttribute(TOUCHED)
            element.removeAttribute(SEEN)
        })
    })

    const alive = () => boot === generation

    /**
     * Insurance, not choreography. Anything this module hid must come back even
     * if its tween never ran -- a context reverted mid-flight, a node Livewire
     * replaced under a running tween. Cheap enough to fire unconditionally.
     */
    const failsafe = (targets, props) => {
        owned(() =>
            gsap.delayedCall(FAILSAFE, () => {
                const stuck = (Array.isArray(targets) ? targets : [targets]).filter(
                    (element) => element && element.isConnected,
                )

                if (stuck.length) {
                    gsap.set(stuck, { clearProps: props })
                }
            }),
        )
    }

    const rtl = document.dir === 'rtl'

    const shared = { gsap, MOTION, scope, owned, alive, failsafe, rtl }

    focusChoreography(shared)

    const validation = validationFeedback(shared)
    const collections = collectionFeedback(shared)

    controlFeedback(shared).attach()
    wizardSteps(shared)

    watchMutations({ ...shared, validation, collections })

    // The one Livewire-side signal that is exactly what it says: Filament
    // dispatches it from InteractsWithSchemas whenever a save or an action fails
    // validation. The observer normally sees the new messages anyway; this is
    // what covers the case where Livewire re-uses an existing node instead of
    // inserting one.
    scope.on(window, 'form-validation-error', () => {
        if (!alive()) {
            return
        }

        requestAnimationFrame(() => {
            if (alive()) {
                validation.sweep()
            }
        })
    })

    // A navigation must not leave a form mid-gesture behind it: the page is
    // about to be replaced, and anything still holding an inline opacity would
    // be handed to the next render.
    scope.on(document, 'livewire:navigating', () => scope.dispose(), { once: true })

    return null
}

/* ------------------------------------------------------------------------- */
/* Lifecycle                                                                  */
/* ------------------------------------------------------------------------- */

/**
 * A bag of teardown callbacks. Every listener, observer and DOM write in this
 * file goes through one, so nothing survives a context revert.
 */
function createScope() {
    const disposers = []
    let disposed = false

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
        get disposed() {
            return disposed
        },
        dispose() {
            if (disposed) {
                return
            }

            disposed = true

            while (disposers.length) {
                try {
                    disposers.pop()()
                } catch (error) {
                    // A failing disposer must not strand the ones behind it.
                    console.warn('[vpn-forge motion] forms cleanup failed', error)
                }
            }
        },
    }
}

function isDisabled(element) {
    return (
        element.hasAttribute('disabled') ||
        element.getAttribute('aria-disabled') === 'true' ||
        element.classList.contains('fi-disabled')
    )
}

/**
 * True for an element that is laid out and on screen. Zero-sized nodes are
 * collapsed sections, closed modals and Alpine templates that have not been
 * shown yet -- animating those is work nobody sees, and pre-hiding them is how
 * something ends up stuck invisible.
 */
function isVisible(element) {
    if (!element || !element.isConnected) {
        return false
    }

    const rect = element.getBoundingClientRect()

    return rect.width > 0 && rect.height > 0
}

/* ------------------------------------------------------------------------- */
/* Focus                                                                      */
/* ------------------------------------------------------------------------- */

/**
 * The field you are in, and everything you are not in.
 *
 * Three parts, deliberately unequal in weight: the input answers with a 2px
 * pulse, its label leans in and stays leaning for as long as you are there, and
 * the other fields in the same section step back a shade. The last one is the
 * only thing in this module that holds a value below its resting state, so it
 * is also the only thing with four independent ways of being undone.
 */
function focusChoreography({ gsap, MOTION, scope, owned, rtl }) {
    /* Sibling fields currently receded. A Set rather than a list because a fast
       tab through a form can dim overlapping groups before the first restore has
       run. */
    const receded = new Set()

    let field = null
    let label = null
    let restoreFrame = 0
    let watchdog = null

    const restore = (instant) => {
        if (!receded.size) {
            return
        }

        const targets = Array.from(receded).filter((element) => element.isConnected)
        receded.clear()

        if (!targets.length) {
            return
        }

        if (instant) {
            gsap.set(targets, { clearProps: 'opacity' })

            return
        }

        owned(() =>
            gsap.to(targets, {
                opacity: 1,
                duration: MOTION.fast,
                ease: MOTION.easeSoft,
                overwrite: 'auto',
                // clearProps rather than leaving `opacity: 1` inline, so the
                // field is byte-for-byte what the server rendered afterwards.
                clearProps: 'opacity',
            }),
        )
    }

    const release = (instant) => {
        if (label) {
            const leaving = label
            label = null

            if (leaving.isConnected && !instant) {
                owned(() =>
                    gsap.to(leaving, {
                        x: 0,
                        duration: MOTION.fast,
                        ease: MOTION.easeSoft,
                        overwrite: 'auto',
                        clearProps: 'transform,transition',
                    }),
                )
            } else {
                gsap.set(leaving, { clearProps: 'transform,transition' })
            }

            leaving.removeAttribute(TOUCHED)
        }

        field = null
        restore(instant)
    }

    scope.add(() => {
        if (restoreFrame) {
            cancelAnimationFrame(restoreFrame)
            restoreFrame = 0
        }

        if (watchdog) {
            watchdog.kill()
            watchdog = null
        }

        // On teardown the settle-back tweens are skipped on purpose: creating
        // tweens while the runtime's context is mid-revert leaves them running
        // after the context has stopped tracking them.
        release(true)
    })

    /**
     * Fields that should step back: everything in the same section except the
     * one being edited, and except anything that contains it or sits inside it
     * (a repeater's own field wrapper contains the fields of every row).
     */
    const siblingsOf = (target) => {
        const container =
            target.closest('.fi-section-content-ctn') ||
            target.closest('.fi-modal-window') ||
            target.closest('.fi-sc-form') ||
            target.closest('form') ||
            target.parentElement

        if (!container) {
            return []
        }

        const all = container.querySelectorAll('.fi-fo-field')
        const kept = []

        for (let i = 0; i < all.length && kept.length < MAX_RECEDE; i += 1) {
            const candidate = all[i]

            if (candidate === target || candidate.contains(target) || target.contains(candidate)) {
                continue
            }

            kept.push(candidate)
        }

        // Below two there is nothing to distinguish the focused field FROM, and
        // dimming the single other field on a form reads as a bug.
        return kept.length > 1 ? kept : []
    }

    /**
     * The label text node. Deliberately `.fi-fo-field-label-content` and not the
     * `<label>`: the wrapper builds that span out of the label string and the
     * required mark, while the `<label>` itself can carry a caller-supplied
     * prefix or suffix -- and a suffix is allowed to be a Filament action, which
     * opens a `position: fixed` modal that a held transform would re-anchor.
     * The guard below is the second line of defence for the same thing.
     */
    const labelOf = (target) => {
        const content = target.querySelector('.fi-fo-field-label-content')

        if (!content || content.querySelector('button, a, input, select, [role="button"]')) {
            return null
        }

        return content
    }

    scope.on(document, 'focusin', (event) => {
        const target = event.target

        if (!target || !target.closest) {
            return
        }

        const next = target.closest('.fi-fo-field')

        if (next && next === field) {
            // Focus moved within the same field -- a suffix action, the second
            // input of a fused group. Cancel the restore `focusout` queued a
            // moment ago rather than replaying the whole gesture.
            if (restoreFrame) {
                cancelAnimationFrame(restoreFrame)
                restoreFrame = 0
            }

            return
        }

        if (!next) {
            // Focus left the form entirely (a header action, the search box).
            // The pending restore is exactly right; leave it alone.
            return
        }

        if (restoreFrame) {
            cancelAnimationFrame(restoreFrame)
            restoreFrame = 0
        }

        release(false)
        field = next

        /* --- the field answers ------------------------------------------- */

        // A pulse, not a lift. `.fi-input-wrp` can hold prefix/suffix actions
        // whose modals are rendered inside it, so the transform has to be gone
        // by the time anything could open one -- which also means the shape of
        // the gesture is "the field acknowledged you", not "the field is raised".
        const wrapper = next.querySelector('.fi-input-wrp') || next.querySelector('.fi-fo-field-content-col')

        if (wrapper && isVisible(wrapper)) {
            const settle = () => gsap.set(wrapper, { clearProps: 'transform,transition' })

            owned(() => {
                // Tailwind puts `transition` (which in v4 includes `transform`)
                // on the input wrapper. Left in place the browser re-eases every
                // frame GSAP writes and the pulse smears into a drift.
                gsap.set(wrapper, { transition: 'none' })

                // `keyframes` belongs to a to() tween -- it describes the whole
                // path, so there is no from-state to give it.
                return gsap.to(wrapper, {
                    keyframes: [
                        { y: -2.5, duration: 0.14, ease: 'power2.out' },
                        { y: 0, duration: 0.4, ease: MOTION.spring },
                    ],
                    overwrite: 'auto',
                    onComplete: settle,
                    onInterrupt: settle,
                })
            })
        }

        /* --- the label leans in ------------------------------------------ */

        label = labelOf(next)

        if (label) {
            label.setAttribute(TOUCHED, '')

            owned(() => {
                gsap.set(label, { transition: 'none' })

                return gsap.to(label, {
                    x: rtl ? -2 : 2,
                    duration: MOTION.fast,
                    ease: MOTION.easeSoft,
                    overwrite: 'auto',
                })
            })
        }

        /* --- everything else steps back ---------------------------------- */

        const siblings = siblingsOf(next)

        if (siblings.length) {
            siblings.forEach((element) => receded.add(element))

            owned(() =>
                gsap.to(siblings, {
                    opacity: RECEDE,
                    duration: MOTION.fast,
                    ease: MOTION.easeSoft,
                    overwrite: 'auto',
                }),
            )
        }

        // Belt and braces. `focusout` is reliable, but a Livewire morph can
        // replace the focused field outright, and then nothing ever fires. The
        // watchdog restores the form if focus has left without us hearing.
        if (watchdog) {
            watchdog.kill()
        }

        watchdog = owned(() =>
            gsap.delayedCall(20, () => {
                if (!field || !field.isConnected || !field.contains(document.activeElement)) {
                    release(false)
                }
            }),
        )
    })

    scope.on(document, 'focusout', () => {
        if (restoreFrame) {
            return
        }

        // One frame of grace: tabbing from one field to the next fires focusout
        // before the matching focusin, and restoring in between would strobe the
        // whole form on every tab.
        restoreFrame = requestAnimationFrame(() => {
            restoreFrame = 0

            const active = document.activeElement

            if (field && active && field.contains(active)) {
                return
            }

            release(false)
        })
    })
}

/* ------------------------------------------------------------------------- */
/* Validation                                                                 */
/* ------------------------------------------------------------------------- */

/**
 * The error message and the field that earned it.
 *
 * The message unrolls from its own top edge with `clip-path` rather than a
 * height tween: the node's box is already in the layout by the time we see it,
 * so a height animation would buy one smooth edge at the price of reflowing
 * every field below it on every frame. The shake is 3px and over in a third of a
 * second -- enough to say "this one", nowhere near enough to be a cartoon.
 */
function validationFeedback({ gsap, MOTION, owned, alive, failsafe }) {
    const CLIP_CLOSED = 'inset(0% 0% 100% 0%)'
    const CLIP_OPEN = 'inset(0% 0% 0% 0%)'

    const reveal = (message, shaken) => {
        if (!message || message.hasAttribute(SEEN) || !alive()) {
            return
        }

        message.setAttribute(SEEN, '')

        if (!isVisible(message)) {
            // Inside a collapsed section or a closed modal. Marked as seen so it
            // is not re-processed, but never hidden -- an unopened reveal is
            // exactly how content ends up stuck at opacity 0.
            return
        }

        owned(() => {
            gsap.set(message, { transition: 'none', willChange: 'clip-path, opacity' })

            return gsap.fromTo(
                message,
                { opacity: 0, y: -4, clipPath: CLIP_CLOSED },
                {
                    opacity: 1,
                    y: 0,
                    clipPath: CLIP_OPEN,
                    duration: 0.34,
                    ease: MOTION.ease,
                    overwrite: 'auto',
                    clearProps: 'opacity,transform,clipPath,transition,willChange',
                },
            )
        })

        failsafe(message, 'opacity,transform,clipPath,transition,willChange')

        /* --- and the field itself ---------------------------------------- */

        const field = message.closest('.fi-fo-field') || message.closest('.fi-sc-fused-group')

        if (!field || shaken.has(field)) {
            return
        }

        shaken.add(field)

        // The input, not the whole field: shaking the label as well reads as the
        // page wobbling rather than as a control refusing a value.
        const target =
            field.querySelector('.fi-input-wrp') ||
            field.querySelector('.fi-fo-field-content-col') ||
            field

        if (!isVisible(target)) {
            return
        }

        const clear = () => gsap.set(target, { clearProps: 'transform,transition' })

        owned(() => {
            gsap.set(target, { transition: 'none' })

            return gsap.to(target, {
                keyframes: [
                    { x: -3, duration: 0.06 },
                    { x: 3, duration: 0.08 },
                    { x: -2, duration: 0.07 },
                    { x: 0, duration: 0.09 },
                ],
                ease: 'power2.out',
                overwrite: 'auto',
                onComplete: clear,
                onInterrupt: clear,
            })
        })

        // The shake holds a transform on a wrapper that may contain a prefix or
        // suffix action, so it gets its own guarantee of being cleared rather
        // than relying on the tween completing.
        failsafe(target, 'transform,transition')
    }

    return {
        /** @param {Element[]} messages */
        revealAll(messages) {
            if (!messages.length) {
                return
            }

            const shaken = new Set()

            messages.forEach((message) => reveal(message, shaken))
        },

        /** Whole-document pass, for errors the observer could not have seen. */
        sweep() {
            const found = Array.from(document.querySelectorAll(`[data-validation-error]:not([${SEEN}])`))

            this.revealAll(found.slice(0, 30))
        },
    }
}

/* ------------------------------------------------------------------------- */
/* Toggles, checkboxes, radios                                                */
/* ------------------------------------------------------------------------- */

/**
 * The controls that hold one bit of state, and are therefore the ones an
 * operator flips fastest. Both effects are springs on `transform` only, so they
 * cost a composite and nothing else, and both clear themselves.
 */
function controlFeedback({ gsap, MOTION, scope, owned, alive }) {
    /**
     * The knob stretches as it travels and settles round.
     *
     * The knob's slide is Filament's own: a Tailwind `translate-x-5` transitioned
     * over 200ms. Tailwind 4 writes that to the `translate` property, so GSAP
     * writing `transform` composes with it instead of replacing it -- but the
     * same utility also puts `transform` in the transition list, which would
     * re-ease the squash frame by frame. So the list is narrowed to the
     * properties CSS still owns, and handed back when the spring lands.
     */
    const springToggle = (toggle) => {
        const knob = toggle.firstElementChild

        if (!knob || !isVisible(knob)) {
            return
        }

        toggle.setAttribute(TOUCHED, '')

        const clear = () => {
            gsap.set(knob, { clearProps: 'transform,transformOrigin,transitionProperty' })
            toggle.removeAttribute(TOUCHED)
        }

        owned(() => {
            gsap.set(knob, {
                transitionProperty: 'translate, opacity, background-color, box-shadow',
            })

            return gsap.fromTo(
                knob,
                { scaleX: 1.22, scaleY: 0.86, transformOrigin: '50% 50%' },
                {
                    scaleX: 1,
                    scaleY: 1,
                    duration: 0.55,
                    ease: MOTION.spring,
                    overwrite: 'auto',
                    onComplete: clear,
                    onInterrupt: clear,
                },
            )
        })
    }

    /**
     * A checkbox or radio taking a value. Checking springs up from small, which
     * reads as the mark landing; unchecking settles down from slightly large,
     * which reads as it being let go. Same gesture, opposite direction, so the
     * two states never feel interchangeable.
     */
    const popBox = (input) => {
        if (!isVisible(input)) {
            return
        }

        const on = input.checked

        owned(() =>
            gsap.fromTo(
                input,
                { scale: on ? 0.68 : 1.14 },
                {
                    scale: 1,
                    duration: on ? 0.5 : 0.34,
                    ease: on ? MOTION.spring : MOTION.easeSoft,
                    overwrite: 'auto',
                    clearProps: 'transform',
                },
            ),
        )
    }

    return {
        attach() {
            // click, not change: `.fi-toggle` is a <button role="switch"> driven
            // by Alpine, so it never fires a change event. A keyboard activation
            // produces a click too, which is what makes this cover Space/Enter.
            scope.on(
                document,
                'click',
                (event) => {
                    if (!alive() || event.button > 0) {
                        return
                    }

                    const target = event.target

                    if (!target || !target.closest) {
                        return
                    }

                    const toggle = target.closest('.fi-toggle')

                    if (toggle && !toggle.classList.contains('fi-hidden') && !isDisabled(toggle)) {
                        springToggle(toggle)
                    }
                },
                { passive: true },
            )

            scope.on(
                document,
                'change',
                (event) => {
                    const target = event.target

                    if (!alive() || !target || !target.matches) {
                        return
                    }

                    if (target.matches('.fi-checkbox-input, .fi-radio-input') && !isDisabled(target)) {
                        popBox(target)
                    }
                },
                { passive: true },
            )
        },
    }
}

/* ------------------------------------------------------------------------- */
/* Collections: chips, options, repeater items                                */
/* ------------------------------------------------------------------------- */

/**
 * Everything in a form that arrives in ones: a tag, a selected value, an option
 * in an open dropdown, a repeater row.
 *
 * Insertion is straightforward -- the node exists, so it is animated into place.
 * Removal is the interesting half. By the time a MutationObserver hears about a
 * deletion the node is gone and the survivors have already jumped up into the
 * hole, so there is nothing left to animate. What this does instead is take a
 * Flip snapshot of the container the instant a pointer goes down inside it, and
 * -- if a child disappears shortly afterwards -- play the survivors back from
 * where they used to be. That is the "height collapse": the list visibly closes
 * over the deleted row, entirely in transforms, with no reflow at all.
 */
function collectionFeedback({ gsap, MOTION, scope, owned, alive, failsafe }) {
    /* The most recent pre-deletion snapshot. One is enough: a deletion always
       follows the pointerdown that caused it within a few hundred ms. */
    let snapshot = null

    /* Beyond this the snapshot describes a layout that has since scrolled,
       re-rendered or been resized, and replaying it would fling rows around. */
    const SNAPSHOT_TTL = 2500

    /* When the option cascade last played, so a search does not replay it once
       per keystroke. */
    let lastOptions = 0

    const childrenOf = (container) =>
        Array.from(container.children).filter((child) => child.nodeType === 1 && isVisible(child))

    scope.on(
        document,
        'pointerdown',
        (event) => {
            if (!alive() || event.button > 0) {
                return
            }

            const target = event.target

            if (!target || !target.closest) {
                return
            }

            const container = target.closest(SETTLE_CTN)

            if (!container) {
                snapshot = null

                return
            }

            const children = childrenOf(container).slice(0, 40)

            if (children.length < 2) {
                snapshot = null

                return
            }

            // One measuring pass, on a pointer event -- not on a scroll or a
            // frame loop. Flip does the reads itself and writes nothing yet.
            snapshot = {
                container,
                state: Flip.getState(children),
                at: performance.now(),
                count: children.length,
            }
        },
        { passive: true },
    )

    scope.add(() => {
        snapshot = null
    })

    /**
     * Play the survivors of a deletion back from where they were.
     *
     * @param {Element} container
     */
    const settle = (container) => {
        if (!snapshot || snapshot.container !== container || !alive()) {
            return
        }

        if (performance.now() - snapshot.at > SNAPSHOT_TTL) {
            snapshot = null

            return
        }

        const survivors = childrenOf(container)
        const state = snapshot.state

        // Only a genuine removal. A reorder is left alone on purpose: the
        // repeater and the tags input are both dragged with a sortable library
        // that animates the swap itself, and two engines moving the same row is
        // how a row ends up somewhere neither of them intended.
        if (!survivors.length || survivors.length >= snapshot.count) {
            snapshot = null

            return
        }

        snapshot = null

        owned(() =>
            Flip.from(state, {
                targets: survivors,
                duration: MOTION.base,
                ease: MOTION.ease,
                // Items already carry their own margins and the container is a
                // plain flex/grid box, so there is nothing to take out of flow.
                absolute: false,
                onComplete: () =>
                    gsap.set(
                        survivors.filter((element) => element.isConnected),
                        { clearProps: 'transform,willChange' },
                    ),
            }),
        )

        // A repeater row holds controls, and every one of those controls can
        // open a modal rendered inside the row. Flip's transform must not
        // outlive the collapse under any circumstances, including the tween
        // never reaching its onComplete.
        failsafe(survivors, 'transform,willChange')
    }

    return {
        /**
         * A tag, or a value badge on a multi-select. Springs up from small,
         * which is the one place in this panel a chip is allowed to be playful:
         * it is the acknowledgement that the thing you typed became a value.
         *
         * @param {Element[]} chips
         */
        chipsAdded(chips) {
            const visible = chips.filter(isVisible).slice(0, MAX_STAGGER)

            if (!visible.length) {
                return
            }

            owned(() => {
                gsap.set(visible, { transition: 'none' })

                // Opacity runs on its own non-overshooting ease: `back.out`
                // would push it past 1 on the way, and the tag's text should be
                // readable before the chip has finished settling.
                gsap.fromTo(
                    visible,
                    { opacity: 0 },
                    {
                        opacity: 1,
                        duration: MOTION.fast,
                        ease: MOTION.easeSoft,
                        stagger: { amount: 0.16, from: 'start' },
                    },
                )

                return gsap.fromTo(
                    visible,
                    { scale: 0.6, y: 3 },
                    {
                        scale: 1,
                        y: 0,
                        duration: 0.5,
                        ease: MOTION.spring,
                        stagger: { amount: 0.16, from: 'start' },
                        clearProps: 'transform,opacity,transition',
                    },
                )
            })

            return visible
        },

        /**
         * Options appearing in an open select, or replaced by a search. A short
         * cascade only -- this is a list you are reading, and anything longer
         * than a moment turns picking a cipher into waiting for a menu.
         *
         * @param {Element[]} options
         */
        optionsAdded(options) {
            // A searchable select rebuilds its whole list on every keystroke.
            // Re-cascading it each time turns typing three letters into three
            // overlapping animations of the thing you are trying to read, so
            // after the first one the list simply updates.
            const now = performance.now()

            if (now - lastOptions < 400) {
                lastOptions = now

                return
            }

            const visible = options.filter(isVisible).slice(0, MAX_STAGGER)

            if (!visible.length) {
                return
            }

            lastOptions = now

            owned(() => {
                gsap.set(visible, { transition: 'none' })

                return gsap.fromTo(
                    visible,
                    { opacity: 0, y: -5 },
                    {
                        opacity: 1,
                        y: 0,
                        duration: 0.3,
                        ease: MOTION.easeSoft,
                        stagger: { amount: 0.14, from: 'start' },
                        clearProps: 'transform,opacity,transition',
                    },
                )
            })

            return visible
        },

        /**
         * A repeater or builder row being added. It opens from its own top edge
         * -- clip-path, so the rows below it are not re-laid-out on the way --
         * while rising the last few pixels into place.
         *
         * @param {Element[]} items
         */
        itemsAdded(items) {
            const visible = items.filter(isVisible).slice(0, 8)

            if (!visible.length) {
                return
            }

            owned(() => {
                gsap.set(visible, { transition: 'none', willChange: 'clip-path, transform, opacity' })

                return gsap.fromTo(
                    visible,
                    { opacity: 0, y: -10, clipPath: 'inset(0% 0% 100% 0%)' },
                    {
                        opacity: 1,
                        y: 0,
                        clipPath: 'inset(0% 0% 0% 0%)',
                        duration: MOTION.base,
                        ease: MOTION.ease,
                        stagger: MOTION.stagger,
                        // The clip has to go: left inline it would keep clipping
                        // the row's own dropdowns and any modal an action inside
                        // it opens, for the life of the page.
                        clearProps: 'opacity,transform,clipPath,transition,willChange',
                    },
                )
            })

            return visible
        },

        settle,
    }
}

/* ------------------------------------------------------------------------- */
/* Mutation dispatch                                                          */
/* ------------------------------------------------------------------------- */

/**
 * One observer for the whole module.
 *
 * Everything this file reacts to arrives as DOM: Livewire morphs an error
 * message in, Alpine's `x-for` appends a tag, a select builds its option list.
 * Rather than one observer per feature, there is one on the body whose records
 * are coalesced into a single frame and then classified once.
 *
 * The cost of a body-wide observer on this panel is table polling, which
 * re-creates rows every 30-60s. Those records are dropped by the first check in
 * the loop, before anything is queried.
 */
function watchMutations({ gsap, scope, owned, alive, failsafe, validation, collections }) {
    if (typeof MutationObserver !== 'function') {
        return
    }

    let frame = 0
    let added = []
    let lost = new Set()

    const flush = () => {
        frame = 0

        const nodes = added
        const containers = lost

        added = []
        lost = new Set()

        if (!alive()) {
            return
        }

        const errors = []
        const chips = []
        const options = []
        const items = []

        /* Containers that GAINED a child in this same pass. A container that
           both gained and lost one was re-rendered wholesale, not edited, and
           replaying the old geometry over the new list would fling rows around
           -- so those are excluded from the collapse below. */
        const grew = new Set()

        const classify = (element) => {
            if (element.matches('[data-validation-error]')) {
                errors.push(element)

                return
            }

            if (element.matches('.fi-select-input-option')) {
                options.push(element)

                return
            }

            if (element.matches(LIST_ITEM)) {
                items.push(element)
                grew.add(element.parentElement)

                return
            }

            // A badge is only ours inside a chip container -- the same class is
            // all over the tables and the sidebar.
            if (element.matches('.fi-badge')) {
                const parent = element.parentElement

                if (parent && parent.matches(CHIP_CTN)) {
                    chips.push(element)
                    grew.add(parent)
                }
            }
        }

        let budget = MAX_BATCH

        for (const node of nodes) {
            if (budget <= 0) {
                break
            }

            if (!node.isConnected) {
                continue
            }

            classify(node)
            budget -= 1

            // A whole container can arrive at once -- the options list of a
            // select that just opened, a section Livewire rendered late. Only
            // walk into nodes that have children at all.
            if (!node.firstElementChild) {
                continue
            }

            const inner = node.querySelectorAll(INTERESTING)

            for (let i = 0; i < inner.length && budget > 0; i += 1) {
                classify(inner[i])
                budget -= 1
            }
        }

        if (errors.length) {
            validation.revealAll(errors)
        }

        if (chips.length) {
            const animated = collections.chipsAdded(chips)

            if (animated && animated.length) {
                failsafe(animated, 'transform,opacity,transition')
            }
        }

        if (options.length) {
            const animated = collections.optionsAdded(options)

            if (animated && animated.length) {
                failsafe(animated, 'transform,opacity,transition')
            }
        }

        if (items.length) {
            const animated = collections.itemsAdded(items)

            if (animated && animated.length) {
                failsafe(animated, 'opacity,transform,clipPath,transition,willChange')
            }
        }

        containers.forEach((container) => {
            if (container.isConnected && !grew.has(container)) {
                collections.settle(container)
            }
        })
    }

    const observer = new MutationObserver((records) => {
        for (const record of records) {
            const target = record.target

            if (!target || target.nodeType !== 1) {
                continue
            }

            // Table internals churn on every poll and contain nothing this
            // module owns. One `closest` per record, before any query.
            if (target.closest('.fi-ta-table, .fi-ta-content')) {
                continue
            }

            for (let i = 0; i < record.addedNodes.length; i += 1) {
                const node = record.addedNodes[i]

                if (node.nodeType === 1) {
                    added.push(node)
                }
            }

            if (record.removedNodes.length && target.matches(SETTLE_CTN)) {
                lost.add(target)
            }
        }

        if (!added.length && !lost.size) {
            return
        }

        if (!frame) {
            frame = requestAnimationFrame(flush)
        }
    })

    observer.observe(document.body, { childList: true, subtree: true })

    scope.add(() => {
        observer.disconnect()

        if (frame) {
            cancelAnimationFrame(frame)
            frame = 0
        }

        added = []
        lost = new Set()
    })

    // Anything already on the page when this run started -- an edit form that
    // rendered with its errors, a modal reopened on a failed save. The observer
    // cannot have seen those, and an error nobody drew attention to is the one
    // failure mode of this whole section. Delayed a beat so it does not land in
    // the middle of the entrance sequence still moving the form into place.
    owned(() =>
        gsap.delayedCall(0.12, () => {
            if (alive()) {
                validation.sweep()
            }
        }),
    )
}

/* ------------------------------------------------------------------------- */
/* Wizard                                                                     */
/* ------------------------------------------------------------------------- */

/**
 * Steps arrive from the direction you are travelling.
 *
 * Filament's wizard is pure Alpine: `x-bind:class` puts `fi-active` on one
 * `.fi-sc-wizard-step` and the stylesheet hides the rest with `invisible
 * absolute h-0`. There is no event to hook, so the active class is watched
 * directly -- an attribute observer on the steps themselves, which is a handful
 * of nodes rather than the whole document.
 *
 * Direction comes from comparing document order, so "back" genuinely reads as
 * going back rather than as another forward slide.
 */
function wizardSteps({ gsap, MOTION, scope, owned, alive, failsafe, rtl }) {
    if (typeof MutationObserver !== 'function') {
        return
    }

    const wizards = gsap.utils.toArray('.fi-sc-wizard')

    if (!wizards.length) {
        return
    }

    const observer = new MutationObserver((records) => {
        if (!alive()) {
            return
        }

        const seen = new Set()

        records.forEach((record) => {
            const step = record.target

            if (!step || step.nodeType !== 1) {
                return
            }

            const wizard = step.closest('.fi-sc-wizard')

            if (wizard && !seen.has(wizard)) {
                seen.add(wizard)
                sync(wizard)
            }
        })
    })

    scope.add(() => observer.disconnect())

    /* Where each wizard was when we last looked. A Map, not an attribute: this
       is bookkeeping, not state the page should carry. */
    const positions = new Map()

    const stepsOf = (wizard) =>
        gsap.utils
            .toArray(wizard.querySelectorAll('.fi-sc-wizard-step'))
            .filter((step) => step.closest('.fi-sc-wizard') === wizard)

    function sync(wizard) {
        const steps = stepsOf(wizard)
        const next = steps.findIndex((step) => step.classList.contains('fi-active'))

        if (next < 0) {
            return
        }

        const previous = positions.get(wizard)
        positions.set(wizard, next)

        if (previous === undefined || previous === next) {
            return
        }

        const step = steps[next]

        if (!isVisible(step)) {
            return
        }

        // Forward: the new panel comes in from the trailing edge, as though the
        // form were a strip being pulled along. Back: from the leading edge.
        const direction = next > previous ? 1 : -1
        const travel = (rtl ? -direction : direction) * 34

        owned(() => {
            gsap.set(step, { transition: 'none' })

            return gsap.fromTo(
                step,
                { opacity: 0, x: travel },
                {
                    opacity: 1,
                    x: 0,
                    duration: MOTION.base,
                    ease: MOTION.ease,
                    overwrite: 'auto',
                    // The step is full of controls whose modals render inside
                    // it, so the transform cannot outlive the slide.
                    clearProps: 'opacity,transform,transition',
                },
            )
        })

        failsafe(step, 'opacity,transform,transition')

        /* --- the fields land after the panel does ------------------------- */

        const fields = gsap.utils
            .toArray(step.querySelectorAll('.fi-fo-field'))
            .filter((field) => {
                const parent = field.parentElement

                return (
                    field.closest('.fi-sc-wizard-step') === step &&
                    !(parent && parent.closest('.fi-fo-field'))
                )
            })
            .slice(0, 10)

        if (fields.length) {
            // Movement only, no second opacity ramp: the panel above is already
            // fading in, and two nested fades multiply -- the fields would spend
            // the first third of the transition at a quarter alpha, which on a
            // form full of hostnames and ports is unreadable for no reason.
            owned(() =>
                gsap.fromTo(
                    fields,
                    { y: 10 },
                    {
                        y: 0,
                        duration: 0.45,
                        ease: MOTION.ease,
                        stagger: { amount: 0.2, from: 'start' },
                        delay: 0.06,
                        overwrite: 'auto',
                        clearProps: 'transform',
                    },
                ),
            )

            failsafe(fields, 'transform')
        }

        /* --- and the header marks where you are --------------------------- */

        const marker = wizard.querySelector(
            '.fi-sc-wizard-header-step.fi-active .fi-sc-wizard-header-step-icon-ctn',
        )

        if (marker && isVisible(marker)) {
            owned(() =>
                gsap.fromTo(
                    marker,
                    { scale: 0.76 },
                    {
                        scale: 1,
                        duration: 0.5,
                        ease: MOTION.spring,
                        overwrite: 'auto',
                        clearProps: 'transform',
                    },
                ),
            )
        }
    }

    wizards.forEach((wizard) => {
        const steps = stepsOf(wizard)

        if (!steps.length) {
            return
        }

        // Recorded, not animated: the step an operator lands on is where they
        // already are, and sliding it in would be the panel narrating a
        // transition that never happened.
        positions.set(
            wizard,
            Math.max(
                0,
                steps.findIndex((step) => step.classList.contains('fi-active')),
            ),
        )

        steps.forEach((step) => observer.observe(step, { attributes: true, attributeFilter: ['class'] }))
    })
}
