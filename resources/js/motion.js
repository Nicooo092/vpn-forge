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
 * Incremented on every boot, so work scheduled by a previous run can tell that
 * it has been superseded and quietly do nothing.
 */
let generation = 0

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
    // ScrollTriggers are registered globally, not on the context, so they have
    // to be killed by hand or every navigation leaves its set behind.
    ScrollTrigger.getAll().forEach((trigger) => trigger.kill())

    if (context) {
        context.revert()
        context = null
    }
}

function boot() {
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
                module({ gsap, ScrollTrigger, MOTION })
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
    const run = ++generation

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
 * claim. Booting once per URL rather than once per event fixes it.
 */
let bootedHref = null

function bootFor(href) {
    bootedHref = href
    boot()
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => bootFor(location.href), { once: true })
} else {
    bootFor(location.href)
}

// Filament SPA navigation: the body is swapped in place, so re-run everything
// -- but only when it is genuinely a different page.
document.addEventListener('livewire:navigated', () => {
    if (bootedHref === location.href) {
        return
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
    window.Livewire?.hook?.('morphed', () => {
        queueRefresh()
        bridgeUpdate()
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
 */
let bridgeQueued = false

function bridgeUpdate() {
    if (bridgeQueued) {
        return
    }

    bridgeQueued = true
    requestAnimationFrame(() => {
        bridgeQueued = false
        document.dispatchEvent(new CustomEvent('livewire:update', { bubbles: true }))
    })
}

// A reduced-motion preference can be toggled while the panel is open.
window.matchMedia('(prefers-reduced-motion: reduce)').addEventListener('change', boot)
