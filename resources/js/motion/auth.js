/*
 * The auth screen.
 * ----------------
 * The login page is the first thing anyone sees of this panel, and until now it
 * got nothing: entrance.js animates panel chrome -- topbar, sidebar,
 * `.fi-page-content` -- and none of that exists here. Filament renders auth
 * pages through a completely separate layout (`.fi-simple-layout` ->
 * `.fi-simple-main` -> `.fi-simple-page`, see
 * vendor/filament/filament/resources/views/components/layout/simple.blade.php),
 * so this module is the whole of the motion that screen gets.
 *
 * What it builds, as one movement:
 *
 *   - an ambient field behind the card: two slow amber auroras and a drifting
 *     mesh of nodes that link up when they come near each other. A VPN is a set
 *     of tunnels between endpoints, so that is what the background is. It is
 *     decoration and nothing else, which is why it is the one thing here
 *     allowed to stay invisible if its tween never runs.
 *   - the brand mark arriving with weight, on an ease written for it.
 *   - the card assembling: a mask travels down from its top edge as it rises.
 *   - the fields cascading inside it, each field's control lagging its own label
 *     by a beat.
 *   - the submit button settling last, on a spring.
 *   - the failure state: a wrong password nudges the field that is wrong, slides
 *     its message in, and scatters the mesh behind the card.
 *   - the hand-over to the multi-factor challenge, which Filament swaps in
 *     without a page load, so nothing else in the runtime would notice it.
 *
 * Four constraints shape every decision below.
 *
 * 1. NOTHING MAY BE LEFT UNREADABLE. Every opacity ramp lands inside ~0.95s and
 *    the last transform settles by ~1.25s. Two wall-clock rescues then force the
 *    timeline to its end and strip every inline style this file wrote, so a
 *    starved ticker or an interrupted tween cannot leave an operator staring at
 *    a login form they cannot read.
 *
 * 2. NO PERMANENT TRANSFORMS ON THE CARD. `<x-filament-actions::modals />` is
 *    rendered inside `.fi-simple-page`, which is inside `.fi-simple-main` -- and
 *    an element holding a transform becomes the containing block for its
 *    `position: fixed` descendants. A finished GSAP tween leaves
 *    `transform: translate(0px, 0px)` behind, which counts, so `transform` is in
 *    every clearProps list in this file and not just `clipPath`.
 *
 * 3. THE AMBIENT LAYER COSTS WHAT IT SAYS IT COSTS. The auroras only ever hold a
 *    transform, so the compositor carries them. The mesh draws at 30fps into a
 *    deliberately under-sampled backing store, with a node count derived from
 *    the viewport area, and it stops dead when the tab is hidden. This panel
 *    runs on a 2-core box.
 *
 * 4. NOTHING IS INSERTED INTO MARKUP LIVEWIRE OWNS. The ambient layer is a
 *    single fixed host appended to `<body>`, outside the Livewire component
 *    entirely, so a morph can never fight it and it holds no controls.
 *
 * Every selector here was read out of vendor/filament before it was written:
 * `.fi-simple-layout`, `.fi-simple-main`, `.fi-simple-layout-header`,
 * `.fi-simple-header-heading`,
 * `.fi-simple-header-subheading`, `.fi-logo`, `form.fi-sc-form`,
 * `[data-field-wrapper]`, `.fi-fo-field-content-col`, `.fi-sc-actions`,
 * `.fi-one-time-code-input-digit` and `[data-validation-error]`.
 */

import { CustomEase } from 'gsap/CustomEase'

/*
 * Marks every node this module appends to the document. The runtime reverts our
 * gsap.context on each boot but has no way to delete DOM we created, and it
 * discards our return value so we cannot hand it a cleanup function either.
 * Teardown therefore lives here: each run undoes the previous one before it
 * builds anything, and sweeps orphans by attribute in case a run was cut short.
 */
const OWNED_ATTR = 'data-vf-auth'

/*
 * The one ease written for this screen rather than borrowed from the shared
 * vocabulary. It rises fast, overshoots by ~3.5%, dips a hair under and settles
 * -- a mass coming to rest rather than a value being interpolated. `back.out`
 * cannot express the dip and `expo.out` cannot express the overshoot, which is
 * the only reason CustomEase is pulled in at all.
 */
const SETTLE = 'vfAuthSettle'
const SETTLE_PATH = 'M0,0 C0.1,0.55 0.16,1.09 0.42,1.035 C0.62,0.99 0.78,1 1,1'

/*
 * What a tween hands back to the stylesheet when it lands.
 *
 * `transform` is in here for the reason in the file header. `transformOrigin`
 * has to be named separately, because clearing `transform` does not take it with
 * it. `animation` and `transition` are here because motion.css puts both on
 * several of these elements and either one outranks the inline styles GSAP
 * writes -- a live CSS transition re-times every value the tween produces, and a
 * keyframe animation with `fill-mode: both` pins the property outright.
 */
const RELEASE = 'animation,transition,willChange,transform,transformOrigin,opacity'

/* What a rescue strips. Wider than RELEASE: it has to undo anything this file
   could possibly have left behind, including a half-finished wipe. */
const RESCUE = `clipPath,filter,${RELEASE}`

/* Ambient tuning. The node count is derived from viewport area and clamped, so a
   4K monitor does not get two hundred nodes and a phone does not get three. */
const MESH_AREA_PER_NODE = 40000
const MESH_MIN_NODES = 8
const MESH_MAX_NODES = 34
const MESH_LINK = 190
const MESH_FPS = 30
const MESH_MARGIN = 60

/* Everything this run created and must be able to undo. Module scope, because
   the runtime re-invokes the function but never re-imports the module. */
let scope = null

/* CustomEase.create() is global and idempotent, but there is no point paying for
   the path parse again on every navigation. */
let easeReady = false

/**
 * A bag of teardown callbacks. Every listener, timer, ticker callback and DOM
 * insertion in this file goes through one of these.
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
                    console.warn('[vpn-forge motion] auth cleanup failed', error)
                }
            }
        },
    }
}

function disposeScope() {
    if (!scope) {
        return
    }

    const previous = scope
    scope = null
    previous.dispose()
}

/**
 * A stagger expressed as a total `amount` rather than a per-item `each`, so a
 * two-field login form and a six-box challenge both finish spreading in the same
 * fixed window. A fresh object per tween, because GSAP caches a distribution
 * function onto it.
 */
function spread(amount) {
    return { amount, from: 'start' }
}

/**
 * The auth screen's signature sequence.
 *
 * @param {{gsap: import('gsap').gsap, ScrollTrigger: object, MOTION: object}} api
 * @returns {?gsap.core.Timeline}
 */
export function authScreen({ gsap, MOTION }) {
    // Undo the previous run before anything else, and before the guards below:
    // navigating AWAY from the login screen has to take the ambient layer with
    // it, and on that boot none of these selectors match.
    disposeScope()

    if (typeof document === 'undefined' || !document.body) {
        return null
    }

    document.querySelectorAll(`[${OWNED_ATTR}]`).forEach((node) => node.remove())

    const layout = document.querySelector('.fi-simple-layout')
    if (!layout) {
        return null
    }

    // The card. Everything else on this screen lives inside it, so without it
    // there is nothing to choreograph.
    const card = layout.querySelector('.fi-simple-main')
    if (!card) {
        return null
    }

    if (!easeReady) {
        try {
            gsap.registerPlugin(CustomEase)
            CustomEase.create(SETTLE, SETTLE_PATH)
            easeReady = true
        } catch (error) {
            // A refused path must not take the sequence with it -- the spring
            // from the shared vocabulary is a perfectly good stand-in.
            console.warn('[vpn-forge motion] auth ease unavailable', error)
        }
    }

    const settle = easeReady ? SETTLE : MOTION.spring

    scope = createScope()

    /*
     * `gsap.context()` with no argument returns the context we are running
     * inside -- the runtime's. Tweens created later, from a submit handler or a
     * mutation, would otherwise be born with no context at all: nothing would
     * revert them, and the inline transforms they left behind would survive the
     * next navigation frozen mid-animation.
     *
     * The result is fished out through a closure rather than returned, because
     * `context.add()` treats a returned *function* as a cleanup callback.
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

    // gsap.context() reverts tweens, not listeners. This nested context exists
    // only so the runtime's revert() reaches the disposer below.
    gsap.context(() => () => disposeScope())

    // A navigation should take the ambient layer with it at once, rather than
    // leaving it on screen until the next page boots. `once` means the listener
    // removes itself, so repeated boots cannot stack them up.
    const onNavigating = () => disposeScope()
    document.addEventListener('livewire:navigating', onNavigating, { once: true })
    scope.add(() => document.removeEventListener('livewire:navigating', onNavigating))

    /* ------------------------------------------------------------------ *
     * Read phase
     *
     * Every measurement and computed-style read happens here, before the first
     * tween exists, so the browser does one layout pass rather than one per
     * query.
     * ------------------------------------------------------------------ */

    /* Small screens get the same choreography with the amplitude turned down:
       large offsets on a narrow viewport read as things falling into place
       rather than settling. */
    const roomy = window.matchMedia('(min-width: 40rem)').matches
    const amp = roomy ? 1 : 0.6

    /* Filament only renders the simple layout's header -- notifications, user
       menu -- when somebody is signed in. Its absence is therefore the panel
       telling us this is a genuine pre-auth screen, and the only place the
       ambient field belongs. An authenticated simple page (the profile editor)
       gets the assembly sequence and nothing behind it. */
    const chrome = layout.querySelector('.fi-simple-layout-header')
    const preAuth = !chrome

    /* Two nodes when a dark-mode logo is configured, one otherwise. */
    const brand = gsap.utils.toArray(layout.querySelectorAll('.fi-logo'))
    const heading = layout.querySelector('.fi-simple-header-heading')
    const subheading = layout.querySelector('.fi-simple-header-subheading')
    const form = card.querySelector('form.fi-sc-form')

    /* The card's own corner radius, so the wipe follows it instead of squaring
       it off mid-animation. Filament only rounds this card at `sm` and up, so
       the value is read rather than assumed -- and refused unless it is a plain
       pixel length, because an unparseable clip-path is silently ignored and the
       wipe would vanish without a word. */
    const radiusRaw = getComputedStyle(card).borderTopLeftRadius
    const radius = /^\d+(\.\d+)?px$/.test(radiusRaw) ? radiusRaw : '0px'
    const CLIP_HIDDEN = `inset(0% 0% 100% 0% round ${radius})`
    const CLIP_SHOWN = `inset(0% 0% 0% 0% round ${radius})`

    /**
     * Split a form into the groups that each want different treatment.
     *
     * Fields holding a one-time-code input are kept apart from the rest: their
     * digits get the cascade, so an opacity ramp on the wrapper as well would
     * multiply the two and leave the code unreadable for twice as long.
     *
     * @param {HTMLElement} root
     */
    function readForm(root) {
        const wrappers = gsap.utils
            .toArray(root.querySelectorAll('[data-field-wrapper]'))
            .filter((el) => {
                // Only outermost wrappers: a field nested inside another is
                // carried by its parent.
                const parent = el.parentElement

                if (parent && parent.closest('[data-field-wrapper]')) {
                    return false
                }

                // Zero-sized nodes are the placeholders Filament renders for
                // schema components it decided to hide.
                const rect = el.getBoundingClientRect()

                return rect.width > 0 && rect.height > 0
            })

        const digits = gsap.utils.toArray(root.querySelectorAll('.fi-one-time-code-input-digit'))

        const coded = new Set()

        digits.forEach((digit) => {
            const wrapper = digit.closest('[data-field-wrapper]')

            if (wrapper) {
                coded.add(wrapper)
            }
        })

        /* The control column of each ordinary field, so it can lag its own label
           by a beat -- one field arriving in two planes rather than as a block. */
        const plain = []
        const lags = []

        wrappers.forEach((el) => {
            if (coded.has(el)) {
                return
            }

            plain.push(el)

            const column = el.querySelector(':scope > .fi-fo-field-content-col')

            if (column) {
                lags.push(column)
            }
        })

        /* The submit button. Falls back to the actions row itself for a form
           whose actions are rendered by something other than the button
           component. */
        let actions = gsap.utils.toArray(root.querySelectorAll('.fi-sc-actions .fi-btn'))

        if (!actions.length) {
            actions = gsap.utils.toArray(root.querySelectorAll('.fi-sc-actions'))
        }

        return {
            plain,
            coded: wrappers.filter((el) => coded.has(el)),
            digits,
            lags,
            actions,
        }
    }

    /* The last of the reads: readForm() measures every field, and it has to
       happen before the first tween exists. A `from()` tween renders its start
       state the moment it is created, so measuring after the timeline was built
       would read a form already displaced by it -- and would cost a second
       forced layout on top. */
    const formParts = form ? readForm(form) : null

    /* ------------------------------------------------------------------ *
     * Write phase
     * ------------------------------------------------------------------ */

    const mesh = preAuth ? buildAmbient({ gsap, scope, owned }) : null

    /* Everything the sequence hides, so a rescue knows what to put back. */
    const prepared = []

    /** Take elements out of the stylesheet's hands for the length of a tween. */
    function release(tl, els) {
        if (!els || !els.length) {
            return
        }

        tl.set(els, { animation: 'none', transition: 'none' }, 0)
    }

    /**
     * The part of the sequence that belongs to a form. Shared between the
     * arrival and the multi-factor hand-over, which is the same choreography at
     * a different speed.
     *
     * @param {gsap.core.Timeline} tl
     * @param {object} parts  the result of readForm()
     * @param {number} at     position on the timeline, in seconds
     * @param {number} d      duration scalar
     */
    function formSequence(tl, parts, at, d) {
        const { plain, coded, digits, lags, actions } = parts

        if (plain.length) {
            prepared.push(...plain)
            release(tl, plain)

            /* Opacity is a separate, shorter tween on a non-overshooting ease:
               a field's label should be readable well before the field has
               finished arriving. */
            tl.from(
                plain,
                {
                    y: 20 * amp,
                    duration: 0.5 * d,
                    ease: MOTION.ease,
                    stagger: spread(0.2 * d),
                    clearProps: RELEASE,
                },
                at,
            ).from(
                plain,
                {
                    opacity: 0,
                    duration: 0.34 * d,
                    ease: MOTION.easeSoft,
                    stagger: spread(0.2 * d),
                },
                at,
            )
        }

        if (lags.length) {
            // y only. The opacity of these pixels belongs to the wrapper above,
            // and two ramps over the same content multiply. Still handed to the
            // rescue: an interrupted tween would leave a control column sitting
            // nine pixels off its label.
            prepared.push(...lags)

            tl.from(
                lags,
                {
                    y: 9 * amp,
                    duration: 0.5 * d,
                    ease: MOTION.ease,
                    stagger: spread(0.2 * d),
                    clearProps: 'transform,transformOrigin',
                },
                at + 0.06 * d,
            )
        }

        if (coded.length) {
            prepared.push(...coded)
            release(tl, coded)

            tl.from(
                coded,
                { y: 18 * amp, duration: 0.5 * d, ease: MOTION.ease, clearProps: RELEASE },
                at,
            )
        }

        if (digits.length) {
            /* The six boxes of the challenge code are the signature moment of
               that screen, so they get the one spring inside the form. */
            prepared.push(...digits)
            release(tl, digits)
            tl.set(digits, { willChange: 'transform' }, 0)

            tl.from(
                digits,
                {
                    scale: 0.55,
                    y: 10 * amp,
                    duration: 0.55 * d,
                    ease: MOTION.spring,
                    stagger: spread(0.22 * d),
                    clearProps: RELEASE,
                },
                at + 0.1 * d,
            ).from(
                digits,
                {
                    opacity: 0,
                    duration: 0.28 * d,
                    ease: MOTION.easeSoft,
                    stagger: spread(0.22 * d),
                },
                at + 0.1 * d,
            )
        }

        if (actions.length) {
            /* Last, and the only thing on the screen allowed to overshoot:
               everything above has arrived, and this is the panel handing the
               decision back to the operator. */
            prepared.push(...actions)
            release(tl, actions)

            tl.from(
                actions,
                {
                    y: 14 * amp,
                    scale: 0.94,
                    duration: 0.6 * d,
                    ease: MOTION.spring,
                    stagger: spread(0.1 * d),
                    clearProps: RELEASE,
                },
                at + 0.2 * d,
            ).from(
                actions,
                {
                    opacity: 0,
                    duration: 0.3 * d,
                    ease: MOTION.easeSoft,
                    stagger: spread(0.1 * d),
                },
                at + 0.2 * d,
            )
        }
    }

    /**
     * Insurance, not choreography. Forces a timeline to its end and strips every
     * inline style this file could have written. If a tween was interrupted, if
     * the ticker was starved, if Livewire replaced a node mid-flight -- this is
     * what guarantees the operator can read the login form.
     *
     * @param {?gsap.core.Timeline} tl
     * @param {HTMLElement[]} targets
     * @param {number} delay  milliseconds
     */
    function rescue(tl, targets, delay) {
        const timer = window.setTimeout(() => {
            try {
                if (tl && tl.progress() < 1) {
                    tl.progress(1)
                }
            } catch (error) {
                // A timeline that has already been reverted throws here, and the
                // sweep below is the part that actually matters.
            }

            const live = targets.filter((el) => el && el.isConnected)

            if (!live.length) {
                return
            }

            owned(() => {
                // Killed first: a tween that is somehow still running would
                // write its own values straight back over the reset.
                live.forEach((el) => gsap.killTweensOf(el))

                return gsap.set(live, { clearProps: RESCUE })
            })
        }, delay)

        scope.add(() => window.clearTimeout(timer))
    }

    /* --- The arrival ----------------------------------------------------- */

    const tl = gsap.timeline({ defaults: { ease: MOTION.ease } })

    /*
     * Positions on the master timeline, in seconds. Written out rather than
     * derived so the overlap is legible: the brand starts while the card is
     * still opening, the heading while the brand is still settling, the fields
     * while the heading is still settling. The screen reads as one gesture
     * precisely because no phase waits for the one above it to finish.
     */
    const P = { card: 0, chrome: 0.04, brand: 0.1, head: 0.22, sub: 0.3, form: 0.34 }

    if (chrome) {
        prepared.push(chrome)
        release(tl, [chrome])

        tl.from(
            chrome,
            { y: -10, opacity: 0, duration: 0.5, ease: MOTION.easeSoft, clearProps: RELEASE },
            P.chrome,
        )
    }

    /*
     * The card assembles rather than appears: a mask travels down from its top
     * edge while the card itself rises, so the reveal and the surface move at
     * different rates -- which is the whole difference between a wipe and a
     * slide. Its opacity runs on a separate, much shorter tween so the form is
     * legible while the card is still arriving.
     */
    prepared.push(card)
    release(tl, [card])
    tl.set(card, { willChange: 'transform, clip-path' }, 0)

    tl.fromTo(
        card,
        { clipPath: CLIP_HIDDEN, y: 30 * amp, scale: 0.975 },
        {
            clipPath: CLIP_SHOWN,
            y: 0,
            scale: 1,
            duration: 0.85,
            ease: MOTION.ease,
            clearProps: `clipPath,${RELEASE}`,
        },
        P.card,
    ).from(card, { opacity: 0, duration: 0.4, ease: MOTION.easeSoft }, P.card)

    if (brand.length) {
        /*
         * The brand is the anchor of the whole arrival, so it gets the weight:
         * it drops, overshoots a hair on the signature ease, and comes to rest.
         * The short defocus resolving with it is confined to this one small text
         * node and to wider viewports -- `filter` forces a re-raster every
         * frame, and this panel runs on a 2-core box.
         */
        prepared.push(...brand)
        release(tl, brand)
        tl.set(brand, { willChange: 'transform, filter, opacity' }, 0)

        tl.from(
            brand,
            { y: -20 * amp, scale: 0.82, duration: 0.85, ease: settle, clearProps: RELEASE },
            P.brand,
        )

        const from = { opacity: 0 }
        const to = { opacity: 1, duration: 0.5, ease: MOTION.ease, clearProps: 'filter' }

        if (roomy) {
            from.filter = 'blur(9px)'
            to.filter = 'blur(0px)'
        }

        tl.fromTo(brand, from, to, P.brand)
    }

    if (heading) {
        prepared.push(heading)
        release(tl, [heading])

        tl.from(
            heading,
            { y: 16 * amp, opacity: 0, duration: 0.6, ease: MOTION.ease, clearProps: RELEASE },
            P.head,
        )
    }

    if (subheading) {
        prepared.push(subheading)
        release(tl, [subheading])

        tl.from(
            subheading,
            { y: 12 * amp, opacity: 0, duration: 0.55, ease: MOTION.ease, clearProps: RELEASE },
            P.sub,
        )
    }

    if (formParts) {
        formSequence(tl, formParts, P.form, 1)
    }

    // The designed sequence lands its last opacity by ~0.95s and its last
    // transform by ~1.25s. The first rescue sits just past that; the second is
    // late enough to catch anything Livewire deferred past the first.
    rescue(tl, prepared, 1500)
    rescue(tl, prepared, 3500)

    /* --- Live states ----------------------------------------------------- */

    watchForm({
        gsap,
        MOTION,
        scope,
        owned,
        card,
        form,
        heading,
        subheading,
        mesh,
        prepared,
        readForm,
        formSequence,
        rescue,
    })

    return tl
}

/* ------------------------------------------------------------------------- */
/* Failure and hand-over                                                      */
/* ------------------------------------------------------------------------- */

/**
 * The two things that happen on this screen without a page load.
 *
 * A wrong password is thrown as a validation exception straight out of
 * `Login::authenticate()`, so it never passes through the schema validator and
 * never dispatches Filament's `form-validation-error` event. The only signal is
 * that Livewire patched an error message into the DOM.
 *
 * And the multi-factor challenge replaces the login form inside the same
 * Livewire component, which is not a navigation, so the runtime never re-runs
 * this module for it.
 *
 * Both are therefore watched with one MutationObserver over the card, coalesced
 * into a single animation frame. The failure branch is armed by the submit that
 * caused it rather than by the identity of the message node: Livewire's morph
 * reuses that node when the message is unchanged, so two wrong passwords in a
 * row would otherwise get one acknowledgement between them.
 */
function watchForm(api) {
    const {
        gsap,
        MOTION,
        scope,
        owned,
        card,
        form,
        heading,
        subheading,
        mesh,
        prepared,
        readForm,
        formSequence,
        rescue,
    } = api

    if (typeof MutationObserver !== 'function') {
        return
    }

    /*
     * Everything below is rooted at the CARD rather than at
     * `.fi-simple-page-content`. The card is rendered by the layout, outside the
     * Livewire component, so a morph can never replace it -- whereas the content
     * wrapper is the component's own markup, and a listener or an observer bound
     * to an element Livewire replaced is a listener that silently stopped
     * working. Nothing else inside the card carries a form or a validation
     * message, so the wider root costs nothing.
     */

    /* Which form is on screen. Filament gives the login form and the challenge
       form different ids (`form` / `multiFactorChallengeForm`), so a change here
       is exactly the hand-over. Seeded from the DOM so the first observation
       cannot mistake the arrival for a swap. */
    let formId = form ? form.id || '' : ''

    let pending = false
    let pendingTimer = 0
    let frame = 0

    const disarm = () => {
        pending = false

        if (pendingTimer) {
            window.clearTimeout(pendingTimer)
            pendingTimer = 0
        }
    }

    scope.add(disarm)

    // `submit` bubbles, and Livewire's `wire:submit` only prevents the default
    // -- it does not stop propagation -- so one delegated listener on the card
    // covers both forms, including an Enter key inside a field.
    scope.on(card, 'submit', (event) => {
        const target = event.target

        if (!target || !target.matches || !target.matches('form.fi-sc-form')) {
            return
        }

        disarm()
        pending = true

        // A response that never produces an error message must not leave the
        // failure branch armed for the rest of the session.
        pendingTimer = window.setTimeout(disarm, 8000)
    })

    /**
     * @param {HTMLElement[]} errors  the `[data-validation-error]` nodes
     */
    function failure(errors) {
        const fields = new Set()

        errors.forEach((node) => {
            const field = node.closest('[data-field-wrapper]')

            if (field) {
                fields.add(field)
            }
        })

        owned(() => {
            /* The message was inserted by the response that just landed, so
               `from` is safe here: its resting state is the one the server
               rendered, and the rescue below forces it if the tween is cut. */
            gsap.fromTo(
                errors,
                { opacity: 0, y: -6 },
                {
                    opacity: 1,
                    y: 0,
                    duration: MOTION.fast,
                    ease: MOTION.easeSoft,
                    stagger: MOTION.stagger,
                    overwrite: 'auto',
                    clearProps: 'transform,transformOrigin,opacity',
                },
            )

            if (fields.size) {
                /* A damped oscillation, not a shake loop: one displacement and a
                   real settle. Confined to the field that is actually wrong --
                   moving the whole card would drag the modal container with it,
                   and would say less about what went wrong. */
                gsap.fromTo(
                    Array.from(fields),
                    { x: -9 },
                    {
                        x: 0,
                        duration: 0.62,
                        ease: 'elastic.out(1.1, 0.34)',
                        stagger: MOTION.stagger,
                        overwrite: 'auto',
                        clearProps: 'transform,transformOrigin',
                    },
                )
            }

            return null
        })

        // The mesh behind the card takes the hit too. This is the one moment the
        // ambient layer is allowed to react to anything.
        if (mesh) {
            mesh.kick()
        }

        rescue(null, Array.from(fields).concat(errors), 1200)
    }

    /**
     * @param {HTMLElement} next  the form Filament just swapped in
     */
    function handover(next) {
        const tl = gsap.timeline({ defaults: { ease: MOTION.ease } })

        /* The heading and subheading were rewritten by the same response -- the
           card is asking a different question now, so it says so. */
        const text = [heading, subheading].filter((el) => el && el.isConnected)

        if (text.length) {
            prepared.push(...text)

            tl.fromTo(
                text,
                { opacity: 0, y: 10 },
                {
                    opacity: 1,
                    y: 0,
                    duration: 0.45,
                    stagger: MOTION.stagger,
                    clearProps: RELEASE,
                },
                0,
            )
        }

        // Two thirds of the arrival's duration: the operator did not just land
        // on this screen, they asked for this step.
        formSequence(tl, readForm(next), 0.06, 0.7)

        rescue(tl, prepared, 1400)
    }

    const flush = () => {
        if (!card.isConnected) {
            return
        }

        const next = card.querySelector('form.fi-sc-form')
        const nextId = next ? next.id || '' : ''

        if (nextId !== formId) {
            formId = nextId
            disarm()

            if (next) {
                handover(next)
            }

            return
        }

        if (!pending) {
            return
        }

        const errors = gsap.utils.toArray(card.querySelectorAll('[data-validation-error]'))

        if (!errors.length) {
            return
        }

        disarm()
        failure(errors)
    }

    const observer = new MutationObserver(() => {
        // A Livewire morph fires dozens of records; the DOM is only worth
        // reading once it has settled, which is the next frame.
        if (frame) {
            return
        }

        frame = requestAnimationFrame(() => {
            frame = 0
            flush()
        })
    })

    observer.observe(card, { childList: true, subtree: true })

    scope.add(() => {
        observer.disconnect()

        if (frame) {
            cancelAnimationFrame(frame)
            frame = 0
        }
    })
}

/* ------------------------------------------------------------------------- */
/* Ambient field                                                              */
/* ------------------------------------------------------------------------- */

/**
 * The background behind the login card: two slow auroras in the panel's own
 * amber, and a drifting mesh of nodes joined by a line whenever they come near
 * each other. Restrained and technical rather than decorative -- it is a picture
 * of what the product does.
 *
 * The whole thing lives in one fixed host at `z-index: -1`, appended to `<body>`.
 * A negative index paints above the page background and below every in-flow
 * element, which is the only way to sit behind the card without touching a
 * single node Livewire owns.
 *
 * @param {{gsap: import('gsap').gsap, scope: object, owned: function}} api
 * @returns {?{kick: function}}
 */
function buildAmbient({ gsap, scope, owned }) {
    const stage = document.createElement('div')
    stage.setAttribute(OWNED_ATTR, 'ambient')
    stage.setAttribute('aria-hidden', 'true')
    stage.style.cssText = [
        'position:fixed',
        'inset:0',
        'z-index:-1',
        'overflow:hidden',
        'pointer-events:none',
        // Its own layout, paint and size island: nothing inside it can ever
        // invalidate the page's layout.
        'contain:strict',
        'opacity:0',
    ].join(';')

    document.body.appendChild(stage)
    scope.add(() => stage.remove())

    /* Everything that has to stop when the tab does. */
    const drifts = []
    let running = false

    /* --- Auroras ---------------------------------------------------------
       Two soft discs sized in vmax, so a resize needs no measurement at all.
       They only ever hold a transform, which means the compositor carries them
       and they cost nothing per frame. */

    const auroras = [
        { size: 105, tint: 'var(--primary-500, #f59e0b)', mix: 20, from: [-14, -12], to: [10, 6], span: 26 },
        { size: 85, tint: 'var(--primary-700, #b45309)', mix: 16, from: [16, 14], to: [-8, -10], span: 34 },
    ]

    auroras.forEach((spec) => {
        const blob = document.createElement('div')

        blob.style.cssText = [
            'position:absolute',
            'top:50%',
            'left:50%',
            `width:${spec.size}vmax`,
            `height:${spec.size}vmax`,
            `margin-top:${-spec.size / 2}vmax`,
            `margin-left:${-spec.size / 2}vmax`,
            'border-radius:50%',
            // The panel's own runtime palette, so this cannot drift from the
            // rest of the UI. The literals are amber-500/700, only reached if
            // Filament's inline palette is ever missing.
            `background:radial-gradient(circle closest-side, color-mix(in oklab, ${spec.tint} ${spec.mix}%, transparent), transparent)`,
            'will-change:transform',
        ].join(';')

        stage.appendChild(blob)

        const drift = owned(() =>
            gsap.fromTo(
                blob,
                { xPercent: spec.from[0], yPercent: spec.from[1], scale: 0.92 },
                {
                    xPercent: spec.to[0],
                    yPercent: spec.to[1],
                    scale: 1.1,
                    duration: spec.span,
                    ease: 'sine.inOut',
                    repeat: -1,
                    yoyo: true,
                },
            ),
        )

        if (drift) {
            drifts.push(drift)
        }
    })

    /* --- Mesh ------------------------------------------------------------- */

    const canvas = document.createElement('canvas')
    canvas.style.cssText = 'position:absolute;top:0;left:0;width:100%;height:100%'
    stage.appendChild(canvas)

    const ctx = canvas.getContext('2d')

    /* The stage fades in rather than appearing with the page. If this tween
       never runs the background simply stays absent, which is the correct
       failure mode for the one layer on this screen that carries nothing. */
    const reveal = () => owned(() => gsap.to(stage, { opacity: 1, duration: 1.4, ease: 'none' }))

    /* Without a 2D context the auroras still work, so the screen is not left
       without a background -- there is simply no mesh over it. */
    if (!ctx) {
        reveal()

        return null
    }

    /*
     * The mesh is drawn in Filament's primary. Reading it back is the only way
     * to be sure the value is one canvas can parse: assigning an invalid colour
     * to fillStyle is silently ignored, so a sentinel goes in first and the
     * assignment is only trusted if it changed.
     */
    function resolveInk() {
        const raw = getComputedStyle(document.documentElement)
            .getPropertyValue('--primary-500')
            .trim()

        if (raw) {
            ctx.fillStyle = '#010203'
            ctx.fillStyle = raw

            if (ctx.fillStyle !== '#010203') {
                return raw
            }
        }

        return '#f59e0b'
    }

    const ink = resolveInk()

    /* A dark panel needs more light behind the card for the mesh to read at all;
       a light one needs less, or the mesh competes with the form. */
    const dark = document.documentElement.classList.contains('dark')
    const linkAlpha = dark ? 0.3 : 0.22
    const dotAlpha = dark ? 0.55 : 0.42

    const clamp = gsap.utils.clamp
    const random = gsap.utils.random

    const nodes = []
    const size = { w: 0, h: 0, scale: 1 }

    function measure() {
        const width = stage.clientWidth || window.innerWidth || 0
        const height = stage.clientHeight || window.innerHeight || 0

        if (!width || !height) {
            return
        }

        const previous = { w: size.w, h: size.h }

        /* The backing store is capped well below a retina ratio on purpose:
           these are 1px lines at a quarter alpha, nothing about them needs four
           device pixels, and the fill cost scales with the square of it. */
        const scale = Math.min(window.devicePixelRatio || 1, 1.25)

        size.w = width
        size.h = height
        size.scale = scale

        canvas.width = Math.round(width * scale)
        canvas.height = Math.round(height * scale)

        const wanted = clamp(
            MESH_MIN_NODES,
            MESH_MAX_NODES,
            Math.round((width * height) / MESH_AREA_PER_NODE),
        )

        if (previous.w && previous.h) {
            // Carry the existing field across a resize rather than re-seeding
            // it, so dragging a window edge does not make the background jump.
            const kx = width / previous.w
            const ky = height / previous.h

            nodes.forEach((node) => {
                node.x *= kx
                node.y *= ky
            })
        }

        while (nodes.length > wanted) {
            nodes.pop()
        }

        while (nodes.length < wanted) {
            /* `bx`/`by` are the node's own slow drift; `vx`/`vy` are what it is
               doing right now. A kick moves the second, and friction walks it
               back to the first. */
            const bx = random(-11, 11)
            const by = random(-11, 11)

            nodes.push({
                x: random(0, width),
                y: random(0, height),
                vx: bx,
                vy: by,
                bx,
                by,
                r: random(1, 2.1),
            })
        }
    }

    measure()

    /**
     * @param {number} dt  seconds since the last drawn frame
     */
    function draw(dt) {
        const { w, h, scale } = size

        if (!w || !h) {
            return
        }

        ctx.setTransform(scale, 0, 0, scale, 0, 0)
        ctx.clearRect(0, 0, w, h)

        const pull = Math.min(1, dt * 0.9)

        for (let i = 0; i < nodes.length; i += 1) {
            const node = nodes[i]

            node.x += node.vx * dt
            node.y += node.vy * dt
            node.vx += (node.bx - node.vx) * pull
            node.vy += (node.by - node.vy) * pull

            // Wrapped rather than bounced: a bounce reads as a boundary, and
            // there is not supposed to be one.
            if (node.x < -MESH_MARGIN) {
                node.x = w + MESH_MARGIN
            } else if (node.x > w + MESH_MARGIN) {
                node.x = -MESH_MARGIN
            }

            if (node.y < -MESH_MARGIN) {
                node.y = h + MESH_MARGIN
            } else if (node.y > h + MESH_MARGIN) {
                node.y = -MESH_MARGIN
            }
        }

        const reach = MESH_LINK * MESH_LINK

        ctx.strokeStyle = ink
        ctx.lineWidth = 1

        for (let i = 0; i < nodes.length; i += 1) {
            const a = nodes[i]

            for (let j = i + 1; j < nodes.length; j += 1) {
                const b = nodes[j]
                const dx = a.x - b.x
                const dy = a.y - b.y
                const span = dx * dx + dy * dy

                if (span > reach) {
                    continue
                }

                // A link fades out as its endpoints separate, so nothing ever
                // snaps into or out of existence.
                ctx.globalAlpha = linkAlpha * (1 - Math.sqrt(span) / MESH_LINK)
                ctx.beginPath()
                ctx.moveTo(a.x, a.y)
                ctx.lineTo(b.x, b.y)
                ctx.stroke()
            }
        }

        ctx.fillStyle = ink
        ctx.globalAlpha = dotAlpha

        for (let i = 0; i < nodes.length; i += 1) {
            const node = nodes[i]

            ctx.beginPath()
            ctx.arc(node.x, node.y, node.r, 0, Math.PI * 2)
            ctx.fill()
        }

        ctx.globalAlpha = 1
    }

    /* One frame in two on a 60Hz display. The mesh moves at walking pace, so
       nothing about it needs 60fps, and halving the fill rate is the single
       biggest saving available here. */
    const step = 1 / MESH_FPS
    let accumulated = 0

    const tick = (time, deltaTime) => {
        let dt = deltaTime / 1000

        // A throttled tab or a dropped frame must not teleport the field.
        if (dt > 0.25) {
            dt = 0.25
        }

        accumulated += dt

        if (accumulated < step) {
            return
        }

        draw(accumulated)
        accumulated = 0
    }

    function start() {
        if (running) {
            return
        }

        running = true
        accumulated = 0
        gsap.ticker.add(tick)
        drifts.forEach((drift) => drift.play())
    }

    function stop() {
        if (!running) {
            return
        }

        running = false
        gsap.ticker.remove(tick)
        drifts.forEach((drift) => drift.pause())
    }

    // The ticker callback is not a tween: the runtime's context revert cannot
    // reach it, so it is unregistered here or it draws forever.
    scope.add(stop)

    // A background tab must not be painting a login screen nobody is looking at.
    // rAF is already throttled there, but "already throttled" is not "stopped",
    // and this is somebody's laptop battery.
    scope.on(document, 'visibilitychange', () => {
        if (document.hidden) {
            stop()
        } else {
            start()
        }
    })

    let resizeFrame = 0

    scope.on(
        window,
        'resize',
        () => {
            if (resizeFrame) {
                return
            }

            resizeFrame = requestAnimationFrame(() => {
                resizeFrame = 0
                measure()
            })
        },
        { passive: true },
    )

    scope.add(() => {
        if (resizeFrame) {
            cancelAnimationFrame(resizeFrame)
            resizeFrame = 0
        }
    })

    if (!document.hidden) {
        start()
    }

    reveal()

    return {
        /**
         * Scatter the field. Adds to each node's current velocity, which the
         * friction term then walks back to its drift over about a second and a
         * half.
         */
        kick() {
            nodes.forEach((node) => {
                node.vx += random(-110, 110)
                node.vy += random(-110, 110)
            })
        },
    }
}
