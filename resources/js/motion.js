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

const MODULES = [entrance, scrollReveal, counters, interactions]

/**
 * Seconds after which the arrival sequence is guaranteed to have finished, so
 * the layout is final and safe to measure. The entrance module budgets ~1.16s
 * on a cold desktop load; this carries a margin on top.
 */
const ENTRANCE_SETTLED = 1.4

let context = null

function prefersReducedMotion() {
    return window.matchMedia('(prefers-reduced-motion: reduce)').matches
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

    // With reduced motion requested the panel is left exactly as the server
    // rendered it: no transforms, no opacity overrides, nothing to unwind.
    if (prefersReducedMotion()) {
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
    requestAnimationFrame(() => ScrollTrigger.refresh())
    gsap.delayedCall(ENTRANCE_SETTLED, () => ScrollTrigger.refresh())
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot, { once: true })
} else {
    boot()
}

// Filament SPA navigation: the body is swapped in place, so re-run everything.
document.addEventListener('livewire:navigated', boot)

// Lazy widgets and tables arrive after their page does. Their content is not
// present at boot, so measurements taken then are stale.
document.addEventListener('livewire:update', () => ScrollTrigger.refresh())

// A reduced-motion preference can be toggled while the panel is open.
window.matchMedia('(prefers-reduced-motion: reduce)').addEventListener('change', boot)
