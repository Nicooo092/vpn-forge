/*
 * vpn-forge motion runtime
 * ------------------------
 * Entry point for the panel's JavaScript motion layer. Everything expensive
 * lives in the modules under ./motion/; this file only owns the lifecycle,
 * which is the part that is easy to get wrong here.
 *
 * Two things make that lifecycle non-obvious:
 *
 * 1. The panel runs Filament SPA navigation (wire:navigate). The document is
 *    never reloaded, so a script that only runs on DOMContentLoaded animates
 *    the first page an operator lands on and then never runs again. Every
 *    module is therefore re-run on `livewire:navigated`.
 *
 * 2. Re-running without cleaning up leaks. GSAP tweens keep hold of nodes that
 *    no longer exist, ScrollTriggers accumulate one set per visited page, and
 *    inline transforms left behind by a killed tween freeze elements
 *    mid-animation. Everything is created inside a gsap.context() so a single
 *    revert() unwinds all of it, and ScrollTriggers are killed explicitly
 *    because they outlive their context.
 */

import { gsap } from 'gsap'
import { ScrollTrigger } from 'gsap/ScrollTrigger'

import { entrance } from './motion/entrance'
import { scrollReveal } from './motion/scroll'
import { counters } from './motion/counters'
import { interactions } from './motion/interactions'
import { pageTransitions } from './motion/transitions'
import { overlays } from './motion/overlays'
import { tables } from './motion/tables'
import { forms } from './motion/forms'
import { charts } from './motion/charts'
import { liveData } from './motion/live'
import { authScreen } from './motion/auth'
import { navigation } from './motion/nav'
import { textReveal } from './motion/text'
import { cursorField } from './motion/cursor'
import { emptyStates } from './motion/empty'
import { physicsInteractions } from './motion/physics'
import { perfGovernor } from './motion/perf'
import { themeSwitch } from './motion/theme'
import { ambientField } from './motion/ambient'
import { iconMotion } from './motion/icons'
import { healthGauges } from './motion/health'
import { reportStory } from './motion/report'
import { searchPalette } from './motion/search'
import { qrReveal } from './motion/qr'
import { onboardingJourney } from './motion/onboarding'
import { celebrate } from './motion/celebrate'
import { depthLayer } from './motion/depth'
import { sparklines } from './motion/sparklines'

gsap.registerPlugin(ScrollTrigger)

/**
 * One easing and duration vocabulary for the whole panel, mirroring the CSS
 * custom properties in motion.css so the JS and CSS layers cannot drift apart.
 */
export const MOTION = {
    ease: 'expo.out',
    easeSoft: 'power3.out',
    spring: 'back.out(1.4)',
    fast: 0.28,
    base: 0.6,
    slow: 1.1,
    stagger: 0.045,
}

/**
 * Order matters only where two modules touch the same element: the arrival
 * sequence runs first so later modules measure a page that is on its way to its
 * resting layout, and the pointer-level work runs last so it binds to whatever
 * the structural modules ended up producing.
 */
const MODULES = [
    // First: it measures the device and publishes a capability tier the other
    // modules read before deciding how much work to do. Everything after it can
    // assume that verdict exists.
    perfGovernor,
    // Structure and arrival.
    entrance,
    textReveal,
    authScreen,
    pageTransitions,
    // Content behaviour.
    scrollReveal,
    counters,
    charts,
    tables,
    forms,
    emptyStates,
    liveData,
    sparklines,
    // Page-specific set pieces.
    healthGauges,
    reportStory,
    onboardingJourney,
    qrReveal,
    // Chrome and overlays.
    navigation,
    overlays,
    searchPalette,
    themeSwitch,
    iconMotion,
    celebrate,
    // Pointer level, and the ambient layer that sits behind everything.
    interactions,
    depthLayer,
    cursorField,
    physicsInteractions,
    ambientField,
]

/**
 * Seconds after which the arrival sequence is guaranteed to have finished, so
 * the layout is final and safe to measure. The entrance module budgets ~1.16s
 * on a cold desktop load; this carries a margin on top.
 */
const ENTRANCE_SETTLED = 1.4

let context = null

/**
 * Disposers handed back by modules. context.revert() unwinds everything GSAP
 * created, but it cannot reach a raw document/window listener or observer a
 * module attached -- only the module knows about those, so it returns a
 * teardown function and the runtime is responsible for calling it before the
 * context itself goes away.
 */
let cleanups = []

/**
 * Incremented on every boot, so work scheduled by a previous run can tell that
 * it has been superseded and quietly do nothing.
 */
let generation = 0

/**
 * When the current run booted, so morph-driven work can tell whether the
 * arrival sequence might still be holding elements off their resting position.
 */
let bootedAt = 0

function prefersReducedMotion() {
    return window.matchMedia('(prefers-reduced-motion: reduce)').matches
}

/**
 * The operator's own kill switch, set with `__vfMotion.force('off')` or by
 * putting `vf-motion` = `off` in localStorage.
 *
 * The performance governor honours it for anything that asks, but a module that
 * never consults the contract would still run, so the decision is enforced here
 * as well: nothing at all is started. Read directly from storage rather than
 * from the governor, because the governor is itself one of the modules this
 * guard decides whether to run.
 */
function motionDisabled() {
    try {
        return window.localStorage.getItem('vf-motion') === 'off'
    } catch {
        // Private mode, or storage blocked by policy. Not a reason to refuse
        // to animate.
        return false
    }
}

function teardown() {
    // Module disposers first, newest to oldest: a raw listener may itself own
    // tweens or triggers, and it has to be gone before the context they lived
    // in is unwound. Each runs isolated -- one broken disposer must not leave
    // every listener behind it alive.
    cleanups.reverse().forEach((cleanup) => {
        try {
            cleanup()
        } catch (error) {
            console.warn('[vpn-forge motion] module cleanup failed', error)
        }
    })
    cleanups = []

    // ScrollTriggers are registered globally, not on the context, so they have
    // to be killed by hand or every navigation leaves its set behind.
    ScrollTrigger.getAll().forEach((trigger) => trigger.kill())

    if (context) {
        context.revert()
        context = null
    }
}

/**
 * Elements the motion system is allowed to rescue.
 *
 * Deliberately a list of things that carry CONTENT rather than a blanket sweep:
 * loading placeholders and our own decorative layers are legitimately faded, and
 * forcing those to full opacity would be its own bug.
 */
const RESCUE = [
    '.fi-wi-stats-overview-stat',
    '.fi-section',
    '.fi-ta-ctn',
    '.fi-wi',
    '.fi-header',
    '.fi-main > *',
    // The page container itself: a transform stuck here scales the whole
    // screen and becomes a containing block for every fixed and absolute
    // descendant, so it is content-critical even though it carries none.
    '.fi-main',
    // Filament teleports modals to <body>, outside .fi-main entirely -- the
    // window is the piece whose stuck opacity or scale leaves a dialog blank.
    '.fi-modal-window',
].join(', ')

/**
 * Force anything on screen but still invisible back to a readable state.
 *
 * Only inline styles are touched, and only on elements that are actually in the
 * viewport -- an element parked below the fold is legitimately waiting for its
 * scroll reveal, and clearing it would defeat the effect for no benefit.
 */
function rescueHiddenContent() {
    const height = window.innerHeight || document.documentElement.clientHeight || 0

    document.querySelectorAll(RESCUE).forEach((element) => {
        // Ours, and decorative: never content.
        if (element.hasAttribute('data-vf-layer') || element.closest('[data-vf-layer]')) {
            return
        }

        const style = element.getAttribute('style')

        // Anything a killed tween can leave behind counts, not just a fade: a
        // transform-only residue (the whole-screen-zoom shape) is stuck motion
        // state exactly as much as a card parked at opacity 0.
        if (!style || !/opacity|transform|translate|scale|rotate|filter|clip/.test(style)) {
            return
        }

        const rect = element.getBoundingClientRect()

        if (rect.bottom <= 0 || rect.top >= height || (!rect.width && !rect.height)) {
            return
        }

        const computed = getComputedStyle(element)

        // Fully visible is not the same as fully at rest: an element that is
        // opaque but still scaled or translated needs its transform cleared.
        if (Number(computed.opacity) >= 0.99 && computed.transform === 'none') {
            return
        }

        gsap.set(element, {
            clearProps: 'opacity,transform,translate,scale,rotate,clipPath,filter,visibility,willChange',
        })
    })
}

function sweepAt(delayMs, run) {
    // The timer id is returned so a caller that reschedules (the morph-driven
    // sweep below) can cancel the pass it is replacing.
    return window.setTimeout(() => {
        if (run !== generation) {
            return
        }

        try {
            rescueHiddenContent()
        } catch (error) {
            console.warn('[vpn-forge motion] rescue sweep failed', error)
        }
    }, delayMs)
}

/*
 * The boot-time sweeps only watch the first few seconds of a page's life, but
 * Livewire can morph the DOM at any time -- a modal action or a poll can kill a
 * module's tween mid-flight and strand its start state long after those sweeps
 * have come and gone. So every burst of morphs earns one more rescue pass,
 * debounced past the point where any morph-reactive animation should have
 * finished, and generation-guarded like every other scheduled sweep.
 */
let morphSweepTimer = 0

function queueMorphSweep() {
    window.clearTimeout(morphSweepTimer)
    morphSweepTimer = sweepAt(1500, generation)
}

function boot() {
    // Superseding the previous run has to happen before anything else: even a
    // boot that goes on to start nothing (reduced motion, kill switch) must
    // invalidate the sweeps and refreshes the run before it left scheduled, or
    // they fire against the page teardown() is about to strip.
    generation += 1

    teardown()

    // With reduced motion requested -- or motion switched off outright -- the
    // panel is left exactly as the server rendered it: no transforms, no
    // opacity overrides, nothing to unwind.
    if (prefersReducedMotion() || motionDisabled()) {
        return
    }

    context = gsap.context(() => {
        MODULES.forEach((module) => {
            try {
                // A module may hand back a disposer for anything revert()
                // cannot reach -- raw listeners, observers. teardown() owns
                // calling it.
                const cleanup = module({ gsap, ScrollTrigger, MOTION })

                if (typeof cleanup === 'function') {
                    cleanups.push(cleanup)
                }
            } catch (error) {
                // One failing module must not take the rest of the panel's
                // motion with it -- and must never break the page itself.
                console.warn('[vpn-forge motion] module failed', error)
            }
        })
    })

    // ScrollTrigger measures with getBoundingClientRect, which includes
    // transforms. Refreshing right here would measure the entrance mid-flight,
    // while its .from() tweens still hold elements tens of pixels off their
    // resting position, and every trigger would be offset by that much for the
    // life of the page.
    //
    // So: one refresh on the next frame, which fixes anything the entrance does
    // not touch, and a second once the arrival sequence has finished and the
    // layout is final. ENTRANCE_SETTLED is the entrance module's own worst-case
    // budget plus a margin.
    // Both are guarded on the run they were scheduled by: they are created
    // after the context closed, so a revert cannot cancel them, and a refresh
    // firing against a torn-down page would resurrect measurements for triggers
    // that no longer exist.
    const run = generation

    bootedAt = performance.now()

    requestAnimationFrame(() => {
        if (run === generation) {
            ScrollTrigger.refresh()
        }
    })

    gsap.delayedCall(ENTRANCE_SETTLED, () => {
        if (run === generation) {
            ScrollTrigger.refresh()
        }
    })

    // The system-wide safety net. Individual modules carry their own failsafes,
    // but each only knows about its own targets -- so a start state applied by
    // one module and never advanced (a timeline that does not tick, an
    // interrupted boot, a page whose structure the module did not expect) leaves
    // content invisible with nothing left to notice. The Server health page
    // rendered completely blank that way: its stat cards sat at the entrance
    // sequence's opening frame forever.
    //
    // Two sweeps on the wall clock, using setTimeout rather than gsap's own
    // scheduler on purpose -- if the ticker is the thing that stopped, a
    // ticker-driven rescue would never arrive either.
    sweepAt(1800, run)
    sweepAt(4000, run)
}

/**
 * The URL the current run was booted for.
 *
 * On a cold load Livewire fires `livewire:navigated` once for the page it
 * arrived on, which lands right after the DOMContentLoaded boot and re-ran the
 * whole system against the same DOM. That second run tore down the first one
 * mid-flight: counters were left showing "0" before snapping to their real
 * value, and the scroll-triggered gauges never animated at all because their
 * triggers had been killed while the DOM still carried their first-appearance
 * claim.
 *
 * Only that single first event may be skipped, and only when it really is the
 * cold-load duplicate. Every later `livewire:navigated` means the body was
 * genuinely swapped -- including a navigation back to the SAME URL, which
 * Livewire performs for a redirect(..., navigate: true) after an action and
 * for a click on the already-active nav item -- so a URL comparison alone
 * would silently leave those fresh bodies without any motion wiring. The
 * latch is consumed by the first event whether or not it was skipped.
 */
let bootedHref = null
let coldNavigatedSeen = false

function bootFor(href) {
    bootedHref = href
    boot()
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => bootFor(location.href), { once: true })
} else {
    bootFor(location.href)
}

// Filament SPA navigation: the body is swapped in place, so re-run everything.
// A same-URL navigation swaps the body exactly like a different-URL one; the
// only event allowed to skip the re-boot is the cold load's duplicate, once.
document.addEventListener('livewire:navigated', () => {
    if (!coldNavigatedSeen) {
        coldNavigatedSeen = true

        if (bootedHref === location.href) {
            return
        }
    }

    bootFor(location.href)
})

/*
 * Lazy widgets and tables arrive after their page does, and a poll can change a
 * table's height, so measurements taken at boot go stale.
 *
 * This used to listen for `livewire:update`, which is a Livewire 2 event that
 * version 3 never dispatches -- so the system had no post-boot refresh at all
 * and every trigger below a lazy widget was measured against a placeholder.
 * `morphed` is the v3 equivalent and fires once per updated component, so the
 * refresh is coalesced into a single call per frame.
 */
let refreshQueued = false

function queueRefresh() {
    // While the arrival sequence still holds containers off their resting
    // position, a refresh would bake those transformed measurements into every
    // trigger -- the same hazard boot() documents for its own refreshes. The
    // settle-point refresh boot() already scheduled re-measures the whole page
    // at ENTRANCE_SETTLED, so a morph landing inside that window coalesces
    // into it instead of stacking another timer.
    if (performance.now() - bootedAt < ENTRANCE_SETTLED * 1000) {
        return
    }

    if (refreshQueued) {
        return
    }

    refreshQueued = true
    requestAnimationFrame(() => {
        refreshQueued = false
        ScrollTrigger.refresh()
    })
}

document.addEventListener('livewire:init', () => {
    window.Livewire?.hook?.('morphed', (payload) => {
        queueRefresh()
        bridgeUpdate(payload?.el)
        queueMorphSweep()
    })
})

/*
 * `livewire:update` is a Livewire 2 event name. Version 3 never dispatches it,
 * but several modules listen for it to notice that a component re-rendered --
 * which is how they detect a poll bringing new values, a table changing page,
 * or a lazy widget arriving. Left alone, all of that was silently dead.
 *
 * Rather than rewrite the listeners in a dozen modules, the runtime synthesises
 * the event from the v3 `morphed` hook. It is deliberately dispatched on the
 * next frame: `morphed` fires while Livewire is still writing the DOM, and a
 * listener that measures there reads a half-updated page.
 *
 * The component roots morphed since the last flush ride along as
 * `detail.elements`, so a listener can scope its work to the subtrees that
 * actually changed instead of rescanning the whole document on every poll
 * tick. Still one dispatch per frame -- per-component dispatch would multiply
 * every listener's work by the number of components in a burst.
 */
let bridgeQueued = false
const bridgeRoots = new Set()

function bridgeUpdate(root) {
    if (root) {
        bridgeRoots.add(root)
    }

    if (bridgeQueued) {
        return
    }

    bridgeQueued = true
    requestAnimationFrame(() => {
        bridgeQueued = false

        const elements = Array.from(bridgeRoots)
        bridgeRoots.clear()

        document.dispatchEvent(
            new CustomEvent('livewire:update', { bubbles: true, detail: { elements } }),
        )
    })
}

// A reduced-motion preference can be toggled while the panel is open.
window.matchMedia('(prefers-reduced-motion: reduce)').addEventListener('change', boot)
