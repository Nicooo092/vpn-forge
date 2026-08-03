/**
 * Arrival choreography -- the orchestrated first second of a page.
 *
 * This is one movement, not a queue of fades. The phases overlap on purpose:
 * the chrome (top bar, sidebar) is still settling when the page heading starts,
 * and the heading is still settling when the content below it begins. Each
 * phase starts before the one above it finishes, which is what makes a load
 * read as a single gesture instead of a checklist.
 *
 * Two constraints shape every number in here:
 *
 *   - This is an operations panel. Nothing may be unreadable for long. Every
 *     opacity ramp finishes inside ~0.9s and the whole sequence lands under
 *     1.2s, even on the busiest page. Where an element gets a longer, more
 *     physical settle (the stat cards, the section wipes), its opacity runs on
 *     a separate, shorter tween -- the value is readable before the card has
 *     finished arriving.
 *
 *   - Every page in the panel has a different DOM. Nothing here assumes a
 *     selector matched. A phase whose targets are absent simply does not exist
 *     in the timeline.
 *
 * @param {{gsap: import('gsap').gsap, MOTION: object}} api
 * @returns {gsap.core.Timeline}
 */

/*
 * Survives across calls: the runtime re-invokes this function on every
 * `livewire:navigated` but never re-imports the module, so a module-level flag
 * is exactly what tells a cold load apart from an SPA hop.
 *
 * The distinction matters. A cold load is the one moment the panel has the
 * user's full attention and nothing else to do, so it gets the full sequence.
 * An SPA hop is a click on a nav item -- the chrome did not change, so
 * re-animating it is a lie, and a slow sequence there makes the panel feel
 * sluggish rather than crafted. Navigations get the page-level phases only, at
 * roughly two-thirds the duration.
 */
let hasBooted = false

/* Filament's sidebar breakpoint (Tailwind `lg`). Below it the sidebar is an
   off-canvas drawer and the top bar carries the navigation, so the chrome
   phases have nothing visible to move. */
const DESKTOP = '(min-width: 64rem)'

/* A section reveal wipes up from its bottom edge. inset() interpolates as
   plain numbers and composites on the GPU, so this costs no more than a fade
   while reading considerably better. */
const CLIP_HIDDEN = 'inset(0% 0% 100% 0%)'
const CLIP_SHOWN = 'inset(0% 0% 0% 0%)'

/*
 * A stagger expressed as a total `amount` rather than a per-item `each`. This
 * is the thing that keeps the sequence bounded: a dashboard with six widgets
 * and a log page with thirty both finish spreading in the same fixed window.
 * A fresh object per tween because GSAP caches a distribution function onto it.
 */
function spread(amount, ease) {
    return ease ? { amount, from: 'start', ease } : { amount, from: 'start' }
}

export function entrance({ gsap, MOTION }) {
    const cold = !hasBooted
    hasBooted = true

    /*
     * A plain media query read, not gsap.matchMedia(). matchMedia's value is
     * that it reverts and re-runs when a breakpoint is crossed -- but an
     * entrance is a one-shot that has already finished by the time anyone
     * resizes a window, so there is nothing to revert to, and its listener
     * would have to be torn down on every navigation or it would accumulate.
     * A single boolean read is both cheaper and more correct here.
     */
    const desktop = window.matchMedia(DESKTOP).matches

    /* Duration scalar and travel-distance scalar. Small screens get the same
       choreography with the amplitude turned down -- large offsets on a narrow
       viewport read as things falling into place rather than settling. */
    const d = cold ? 1 : 0.68
    const amp = desktop ? 1 : 0.55

    const tl = gsap.timeline({ defaults: { ease: MOTION.ease } })

    /*
     * Positions on the master timeline, in seconds. Written out rather than
     * derived so the overlap is legible: the heading starts while the nav is
     * still cascading, the content starts while the heading is still settling.
     * On a navigation the chrome phases are absent, so everything collapses
     * toward zero and the page turns over in about 0.65s.
     */
    const P = cold
        ? { brand: 0.04, nav: 0.1, crumbs: 0.16, head: 0.2, sub: 0.27, act: 0.3, body: 0.32 }
        : { crumbs: 0, head: 0.02, sub: 0.08, act: 0.1, body: 0.06 }

    /*
     * motion.css puts CSS transitions and keyframe animations on several of the
     * elements this module drives (.fi-section, .fi-btn, .fi-badge, the stat
     * cards, and `.fi-main > *`). Both outrank inline styles while they are
     * live: a CSS transition re-times every value GSAP writes, so a tween
     * smears instead of easing, and a keyframe animation with `fill-mode: both`
     * pins the property outright and the tween becomes invisible. Anything this
     * sequence touches therefore opts out for the length of its tween;
     * `clearProps` on the owning tween hands the element back to the stylesheet
     * afterwards.
     */
    const RELEASE = 'animation,transition,willChange'
    function release(els) {
        tl.set(els, { animation: 'none', transition: 'none' }, 0)
    }

    // ------------------------------------------------------------------
    // Read phase. Every measurement happens here, before the first tween is
    // created, so the browser does one layout pass instead of one per query.
    // ------------------------------------------------------------------

    const fold = window.innerHeight * 1.05

    /* Content units that start below the fold are left alone: they are not on
       screen, and animating them here would fight the scroll module that owns
       them. Zero-sized nodes are Livewire's empty render-hook wrappers and
       Alpine's not-yet-shown elements. */
    function onScreen(els) {
        const kept = []

        for (const el of els) {
            const r = el.getBoundingClientRect()

            if (!r.width && !r.height) continue
            if (r.top > fold) continue
            if (r.bottom < -40) continue

            kept.push(el)
        }

        return kept
    }

    /*
     * Partition the page's top-level blocks by what they contain, so each gets
     * the treatment that suits it and nothing gets animated twice. A stat grid
     * animates its cards, not the grid; a block of sections animates the
     * sections, not the wrapper -- otherwise the two opacity ramps multiply and
     * the content spends the first half-second at 25% alpha.
     */
    const contentRoot =
        document.querySelector('.fi-page-content') ?? document.querySelector('.fi-main')

    const stats = []
    const wipes = []
    const risers = []

    if (contentRoot) {
        for (const block of contentRoot.children) {
            const cards = block.querySelectorAll('.fi-wi-stats-overview-stat')

            if (cards.length) {
                stats.push(...cards)
                continue
            }

            /* Only the outermost sections -- a section nested inside another is
               revealed by its parent's wipe. */
            const sections = block.matches('.fi-section')
                ? [block]
                : Array.from(block.querySelectorAll('.fi-section')).filter(
                      (s) => !s.parentElement?.closest('.fi-section'),
                  )

            if (sections.length) {
                wipes.push(...sections)
                continue
            }

            risers.push(block)
        }
    }

    const visibleStats = onScreen(stats)
    const visibleWipes = onScreen(wipes)
    const visibleRisers = onScreen(risers)

    const heading = document.querySelector('.fi-header-heading')
    const subheading = document.querySelector('.fi-header-subheading')
    const crumbs = document.querySelector('.fi-breadcrumbs')
    const actionsCtn = document.querySelector('.fi-header-actions-ctn')

    /* Prefer the individual buttons so they can arrive on their own spring; a
       page whose actions are rendered by something other than the button
       component still gets the container moved. */
    const actionBtns = actionsCtn
        ? (() => {
              const btns = actionsCtn.querySelectorAll('.fi-btn')
              return btns.length ? Array.from(btns) : [actionsCtn]
          })()
        : []

    const subNav = gsap.utils.toArray(
        '.fi-page-sub-navigation-tabs, .fi-page-sub-navigation-sidebar-ctn',
    )

    // ------------------------------------------------------------------
    // Write phase.
    // ------------------------------------------------------------------

    // --- Chrome: the frame of the app settles before anything appears inside it.

    if (cold && desktop) {
        const topbar = document.querySelector('.fi-topbar')

        if (topbar) {
            /* A short drop, not a full yPercent: `.fi-topbar-ctn` is sticky with
               `overflow-x: clip`, which does not clip vertically, so a bar
               sliding in from -100% would travel straight across the page
               content beneath it. */
            release(topbar)
            tl.from(
                topbar,
                { y: -16, opacity: 0, duration: 0.5, clearProps: RELEASE },
                0,
            )
        }

        /* The bar's own controls arrive after the bar itself, so the surface
           lands and is then furnished. */
        const topbarBits = gsap.utils.toArray('.fi-topbar-start > *, .fi-topbar-end > *')

        if (topbarBits.length) {
            release(topbarBits)
            tl.from(
                topbarBits,
                {
                    y: -8,
                    opacity: 0,
                    duration: 0.45,
                    ease: MOTION.easeSoft,
                    stagger: spread(0.12),
                    clearProps: RELEASE,
                },
                0.08,
            )
        }

        const brand = document.querySelector('.fi-sidebar-header-logo-ctn')

        if (brand) {
            /* The brand is the one mark in the panel that is allowed a spring.
               It is the anchor of the whole arrival, so it gets the accent. */
            release(brand)
            tl.from(
                brand,
                {
                    y: -12,
                    scale: 0.9,
                    opacity: 0,
                    duration: 0.7,
                    ease: MOTION.spring,
                    clearProps: RELEASE,
                },
                P.brand,
            )
        }

        /* Nav items and their group labels cascade in document order. The
           easing on the *stagger* is what gives the cascade per-item character:
           `power2.in` bunches the later items closer together, so the list
           accelerates down the sidebar and lands as one, rather than ticking
           off at a constant rate. */
        const navItems = gsap.utils.toArray(
            '.fi-sidebar-nav .fi-sidebar-group-label, .fi-sidebar-nav .fi-sidebar-item',
        )

        if (navItems.length) {
            release(navItems)
            tl.from(
                navItems,
                {
                    x: -18,
                    opacity: 0,
                    duration: 0.5,
                    ease: MOTION.ease,
                    stagger: spread(0.2, 'power2.in'),
                    clearProps: RELEASE,
                },
                P.nav,
            )
        }
    }

    if (!cold && desktop) {
        /* On a navigation the sidebar does not move, but the item you just
           landed on acknowledges the click. One element, one short nudge. */
        const active = document.querySelector('.fi-sidebar-item.fi-active')

        if (active) {
            release(active)
            tl.fromTo(
                active,
                { x: -7 },
                { x: 0, duration: 0.42, ease: MOTION.spring, clearProps: `x,${RELEASE}` },
                0,
            )
        }
    }

    // --- Page header: the title lands first, then what qualifies it.

    if (crumbs) {
        release(crumbs)
        tl.from(
            crumbs,
            { y: -8 * amp, opacity: 0, duration: 0.45 * d, ease: MOTION.easeSoft, clearProps: RELEASE },
            P.crumbs,
        )
    }

    if (heading) {
        /* A short defocus resolving into the title. Confined to the h1 and to
           cold desktop loads on purpose: `filter` forces a re-raster every
           frame, and this panel runs on a 2-core box -- one small text node for
           half a second is affordable, a page of them is not. */
        const useBlur = cold && desktop
        const from = { y: 24 * amp, opacity: 0 }
        const to = {
            y: 0,
            opacity: 1,
            duration: 0.8 * d,
            ease: MOTION.ease,
            clearProps: `filter,${RELEASE}`,
        }

        if (useBlur) {
            from.filter = 'blur(10px)'
            to.filter = 'blur(0px)'
        }

        release(heading)
        tl.set(heading, { willChange: 'transform, filter, opacity' }, 0)
        tl.fromTo(heading, from, to, P.head)
    }

    if (subheading) {
        release(subheading)
        tl.from(
            subheading,
            { y: 14 * amp, opacity: 0, duration: 0.6 * d, ease: MOTION.ease, clearProps: RELEASE },
            P.sub,
        )
    }

    if (actionBtns.length) {
        /* Actions come in from the side rather than from below, so they read as
           belonging to the header's right edge instead of to the content. */
        release(actionBtns)
        tl.from(
            actionBtns,
            {
                x: 14 * amp,
                scale: 0.92,
                opacity: 0,
                duration: 0.55 * d,
                ease: MOTION.spring,
                stagger: spread(0.1 * d),
                clearProps: RELEASE,
            },
            P.act,
        )
    }

    if (subNav.length) {
        release(subNav)
        tl.from(
            subNav,
            {
                y: 12 * amp,
                opacity: 0,
                duration: 0.5 * d,
                ease: MOTION.easeSoft,
                stagger: spread(0.08 * d),
                clearProps: RELEASE,
            },
            P.act,
        )
    }

    // --- Content.

    if (visibleStats.length) {
        /*
         * Stat cards are the closest thing this panel has to a hero, so they
         * get a physical settle: they rise, overshoot very slightly under
         * `back.out`, and come to rest. Opacity is deliberately a separate,
         * faster tween on a non-overshooting ease -- partly because a spring
         * would push opacity past 1 on the way, but mostly because the number
         * on the card should be readable while the card is still settling.
         */
        release(visibleStats)
        tl.set(visibleStats, { willChange: 'transform' }, 0)
        tl.from(
            visibleStats,
            {
                y: 22 * amp,
                scale: 0.94,
                duration: 0.7 * d,
                ease: MOTION.spring,
                stagger: spread(0.14 * d),
                clearProps: RELEASE,
            },
            P.body,
        ).from(
            visibleStats,
            {
                opacity: 0,
                duration: 0.42 * d,
                ease: MOTION.easeSoft,
                stagger: spread(0.14 * d),
            },
            P.body,
        )
    }

    if (visibleWipes.length) {
        /*
         * Sections are revealed by a mask travelling up their own bottom edge
         * while the card itself rises -- the content and its reveal move at
         * different rates, which is what separates a wipe from a slide. The
         * inline clip-path has to be cleared at the end or it would keep
         * clipping the section's ring shadow and any dropdown that opens out of
         * it for the rest of the page's life.
         */
        release(visibleWipes)
        tl.set(visibleWipes, { willChange: 'transform, clip-path' }, 0)
        tl.fromTo(
            visibleWipes,
            { clipPath: CLIP_HIDDEN, y: 26 * amp },
            {
                clipPath: CLIP_SHOWN,
                y: 0,
                duration: 0.66 * d,
                ease: MOTION.ease,
                stagger: spread(0.16 * d),
                clearProps: `clipPath,${RELEASE}`,
            },
            P.body,
        ).from(
            visibleWipes,
            {
                opacity: 0,
                duration: 0.4 * d,
                ease: MOTION.easeSoft,
                stagger: spread(0.16 * d),
            },
            P.body,
        )
    }

    if (visibleRisers.length) {
        /* Everything else -- tables, custom Blade, charts -- rises. Plain on
           purpose: these blocks own their own internal motion, and a wipe over
           a table that is about to poll would fight it. */
        release(visibleRisers)
        tl.from(
            visibleRisers,
            {
                y: 28 * amp,
                opacity: 0,
                duration: 0.62 * d,
                ease: MOTION.ease,
                stagger: spread(0.18 * d),
                clearProps: RELEASE,
            },
            P.body,
        )
    }

    return tl
}
