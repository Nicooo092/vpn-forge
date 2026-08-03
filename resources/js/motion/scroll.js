/**
 * Scroll-driven motion.
 * ---------------------
 * Long pages in this panel -- traffic logs, service reports, backups, change
 * history -- are the ones an operator actually reads top to bottom. This module
 * is what turns them from a document into something that was designed to be
 * scrolled: content below the fold arrives instead of being already there, the
 * page heading recedes in layers as you leave it behind, section headers float
 * a hair against their cards so the page has depth, and a hairline at the top
 * says how far through a long table you are.
 *
 * Three constraints shape every decision in here.
 *
 * 1. This is an operations dashboard. Nothing that is on screen when the page
 *    loads is ever hidden -- only elements measured BELOW the fold are prepared
 *    for a reveal, and two failsafe sweeps force anything still hidden but
 *    visible back to full opacity. An operator must never be unable to read a
 *    value because a tween has not fired.
 *
 * 2. Filament renders action modals (`position: fixed`) inline, inside the
 *    section or table that owns them. Any element with a live `transform`,
 *    `filter` or `clip-path` becomes the containing block for its fixed
 *    descendants, which would anchor those modals to a card instead of the
 *    viewport. Two rules follow. Transforms on cards are transient: the reveal
 *    strips its own inline styles the moment it lands. The scrubbed effects,
 *    which do hold a transform for the life of the page, are only ever pointed
 *    at elements checked to contain nothing interactive -- headings, breadcrumbs
 *    and section headers with no control inside them.
 *
 * 3. Table rows are re-created on every 30-60s poll. Nothing in here touches
 *    them -- the table CONTAINER (`.fi-ta-ctn`) is stable and is what gets
 *    revealed.
 */

/*
 * Marks DOM this module created. The runtime reverts our gsap.context and kills
 * our ScrollTriggers on every navigation, but it has no way to delete nodes we
 * appended to the body -- and it discards our return value, so we cannot hand it
 * a cleanup function either. Teardown therefore lives here: each run undoes the
 * previous one before it builds anything, and sweeps orphans by attribute in
 * case a run was interrupted.
 */
const OWNED_ATTR = 'data-vf-scroll'

/* Reveal targets. Deliberately containers, never rows or cells. */
const REVEAL_PARTS = ['.fi-section', '.fi-ta-ctn', '.fi-wi-widget']
const REVEAL_SELECTOR = REVEAL_PARTS.join(', ')
const REVEAL_SCOPED = REVEAL_PARTS.map((part) => `.fi-main ${part}`).join(', ')

/* A card is wiped open from its bottom edge. The radius matches Filament's
   `--radius-xl` on `.fi-section` so the clip follows the card's own corners
   instead of squaring them off mid-animation. */
const CLIP_FROM = 'inset(0% 0% 16% 0% round 12px)'
const CLIP_TO = 'inset(0% 0% 0% 0% round 12px)'

/* Hard ceiling on how many elements a single page may prepare. A page with more
   sections than this is a list, not a composition, and hiding all of it would
   cost more than it says. */
const MAX_REVEALS = 36

/* Above this scroll speed (px/s) the operator is hunting for something, not
   reading. The reveal collapses to a short, near-instant fade so the page never
   feels like it is lagging behind the wheel. */
const HURRY_VELOCITY = 1500

let disposers = []

function runDisposers() {
    while (disposers.length) {
        const dispose = disposers.pop()

        try {
            dispose()
        } catch (error) {
            // A failing disposer must not strand the ones after it.
        }
    }
}

/**
 * @param {{gsap: import('gsap').gsap, ScrollTrigger: object, MOTION: object}} api
 */
export function scrollReveal({ gsap, ScrollTrigger, MOTION }) {
    // Undo the previous run before anything else: remove the nodes we appended,
    // drop our listeners, revert our matchMedia.
    runDisposers()
    document.querySelectorAll(`[${OWNED_ATTR}]`).forEach((node) => node.remove())

    const main = document.querySelector('.fi-main')
    if (!main) {
        return
    }

    const viewportHeight = () => window.innerHeight || document.documentElement.clientHeight || 0

    // A navigation should take the progress bar with it immediately rather than
    // leaving it on screen until the next page boots. `once` means this listener
    // removes itself, so repeated boots cannot stack them up.
    const onNavigating = () => runDisposers()
    document.addEventListener('livewire:navigating', onNavigating, { once: true })
    disposers.push(() => document.removeEventListener('livewire:navigating', onNavigating))

    /* ------------------------------------------------------------------ *
     * Scroll velocity probe
     *
     * One trigger spanning the whole scroll range, with no animation of its
     * own. Read on demand rather than cached, so what a reveal sees is the
     * speed at the instant it fires.
     * ------------------------------------------------------------------ */
    const probe = ScrollTrigger.create({ start: 0, end: 'max' })

    const readVelocity = () => {
        try {
            return Math.abs(probe.getVelocity())
        } catch (error) {
            return 0
        }
    }

    /* ------------------------------------------------------------------ *
     * Reading progress
     *
     * Scoped to the tallest long table on the page when there is one -- "how
     * far through these 4000 log lines am I" is a more useful answer than "how
     * far down the document am I", and on a table page they are not the same
     * question. Falls back to the document, and is not created at all on a page
     * short enough that the answer is obvious.
     * ------------------------------------------------------------------ */
    function buildProgressBar() {
        const height = viewportHeight()
        if (!height) {
            return
        }

        const tallTable = gsap.utils
            .toArray('.fi-main .fi-ta-ctn')
            .filter((table) => table.offsetHeight > height * 1.6)
            .sort((a, b) => b.offsetHeight - a.offsetHeight)[0]

        const documentIsLong = document.documentElement.scrollHeight > height * 2.2

        if (!tallTable && !documentIsLong) {
            return
        }

        const target = tallTable || document.documentElement

        const track = document.createElement('div')
        track.setAttribute(OWNED_ATTR, 'progress')
        // z-index 35 sits above Filament's sticky topbar (30) and below its
        // modal overlay (40), so the bar reads over the chrome but never over a
        // dialog.
        track.style.cssText = [
            'position:fixed',
            'top:0',
            'left:0',
            'right:0',
            'height:2px',
            'z-index:35',
            'pointer-events:none',
            'opacity:0',
            'visibility:hidden',
        ].join(';')

        const fill = document.createElement('div')
        fill.style.cssText = [
            'height:100%',
            'width:100%',
            'transform:scaleX(0)',
            // The panel's amber primary, read from the palette Filament injects
            // on :root so this cannot drift from the rest of the UI. The literal
            // is amber-500, only reached if that palette is ever missing.
            'background:var(--primary-500, #f59e0b)',
            'box-shadow:0 0 6px 0 var(--primary-500, #f59e0b)',
            'will-change:transform',
        ].join(';')

        track.appendChild(fill)
        document.body.appendChild(track)
        disposers.push(() => track.remove())

        // scaleX rather than width: this updates on every scroll frame and has
        // to stay off the layout path. GSAP owns the origin so it cannot be
        // reset when it writes the transform.
        gsap.set(fill, { transformOrigin: document.dir === 'rtl' ? '100% 50%' : '0% 50%' })

        gsap.fromTo(
            fill,
            { scaleX: 0 },
            {
                scaleX: 1,
                ease: 'none',
                scrollTrigger: {
                    trigger: target,
                    start: 'top top',
                    end: 'bottom bottom',
                    scrub: 0.25,
                },
            },
        )

        // The bar only exists while you are inside the range it measures. On a
        // table-scoped bar that matters: a full amber line sitting there after
        // the table has ended would be stating something untrue.
        ScrollTrigger.create({
            trigger: target,
            start: 'top top',
            end: 'bottom bottom',
            onRefresh: (self) => gsap.set(track, { autoAlpha: self.isActive ? 1 : 0 }),
            onToggle: (self) =>
                gsap.to(track, {
                    autoAlpha: self.isActive ? 1 : 0,
                    duration: MOTION.fast,
                    ease: MOTION.easeSoft,
                }),
        })
    }

    /* ------------------------------------------------------------------ *
     * Layered page-header parallax
     *
     * Breadcrumbs, heading and subheading leave at three different rates, so
     * the top of the page reads as three planes rather than one block sliding
     * away. Applied to the text nodes only -- `.fi-header-actions-ctn` is left
     * alone because the buttons in it open fixed-position modals.
     * ------------------------------------------------------------------ */
    function buildHeaderParallax() {
        const header = document.querySelector('.fi-main .fi-header')
        if (!header) {
            return
        }

        const height = viewportHeight()
        if (!height || document.documentElement.scrollHeight < height * 1.6) {
            return
        }

        const layers = [
            ['.fi-breadcrumbs', -14, 0.15],
            ['.fi-header-heading', -30, 0.3],
            ['.fi-header-subheading', -44, 0.2],
        ]

        const travel = Math.round(height * 0.6)

        // Measured, not assumed: the topbar is 4rem on desktop but the panel can
        // be configured without one, and then the heading should start receding
        // at the top of the viewport instead of 64px down.
        const topbar = document.querySelector('.fi-topbar')
        const chrome = topbar ? Math.round(topbar.getBoundingClientRect().height) : 0

        layers.forEach(([selector, distance, endOpacity]) => {
            const layer = header.querySelector(selector)
            if (!layer) {
                return
            }

            // A scrubbed tween must own its property outright: a CSS transition
            // on the same one turns scrolling into a rubber band. Nothing in the
            // stylesheet transitions these today -- this is so it stays true if
            // the CSS layer grows.
            gsap.set(layer, { transition: 'none' })

            gsap.to(layer, {
                y: distance,
                opacity: endOpacity,
                ease: 'none',
                scrollTrigger: {
                    trigger: header,
                    // Starts as the heading passes under the topbar, which is
                    // the moment it stops being the thing you are looking at.
                    start: `top top+=${chrome}`,
                    end: `+=${travel}`,
                    scrub: 0.35,
                },
            })
        })
    }

    /* ------------------------------------------------------------------ *
     * Section-header float
     *
     * Ten pixels of travel across a section's whole pass through the viewport.
     * The header lags its own card just enough to read as sitting on a nearer
     * plane -- the "sticky header" idea from the brief, minus the layout fight:
     * genuinely pinning it would need a background layer Filament's section
     * does not have, which means restyling.
     *
     * Only headers with nothing interactive inside them qualify. This is the
     * one persistent transform this module leaves on the page, and a persistent
     * transform makes its element the containing block for any fixed-position
     * descendant -- so we make sure there cannot be one.
     * ------------------------------------------------------------------ */
    function buildSectionHeaderFloat(floated) {
        const headers = gsap.utils
            .toArray('.fi-main .fi-section > .fi-section-header')
            .filter((header) => !header.querySelector('button, a, input, select, textarea, [role="button"]'))
            .slice(0, 24)

        headers.forEach((header) => {
            const section = header.parentElement
            if (!section) {
                return
            }

            floated.add(header)

            gsap.set(header, { transition: 'none' })

            gsap.fromTo(
                header,
                { y: 5 },
                {
                    y: -5,
                    ease: 'none',
                    scrollTrigger: {
                        trigger: section,
                        start: 'top bottom',
                        end: 'bottom top',
                        scrub: 0.5,
                    },
                },
            )
        })
    }

    /* ------------------------------------------------------------------ *
     * Reveal on enter
     * ------------------------------------------------------------------ */

    /**
     * Outermost reveal containers currently below the fold.
     *
     * Measured fresh every time a matchMedia branch builds, because a branch can
     * rebuild on resize and a list captured earlier would be stale. All reads
     * happen in one pass before any write, so this costs a single layout.
     */
    function collectBelowFold() {
        const all = gsap.utils.toArray(REVEAL_SCOPED)
        if (!all.length) {
            return []
        }

        // Keep only the outermost of any nesting -- a table inside a section
        // should be revealed once, by the section.
        const known = new Set(all)
        const outermost = all.filter((el) => {
            const parent = el.parentElement
            const ancestor = parent ? parent.closest(REVEAL_SELECTOR) : null

            return !(ancestor && known.has(ancestor))
        })

        const height = viewportHeight()
        if (!height) {
            return []
        }

        // 92% of the viewport, not 100%: an element whose top is a sliver above
        // the fold is already being read, and must not be taken away to be
        // animated back in.
        const fold = height * 0.92
        const rects = outermost.map((el) => el.getBoundingClientRect())
        const below = []

        for (let i = 0; i < outermost.length; i += 1) {
            if (below.length >= MAX_REVEALS) {
                break
            }

            const rect = rects[i]

            // height 0 filters out collapsed sections and anything inside a
            // closed modal, which are not on screen in any meaningful sense.
            if (rect.height > 0 && rect.top > fold) {
                below.push(outermost[i])
            }
        }

        return below
    }

    /**
     * Force anything hidden but visible back to full opacity.
     *
     * Insurance, not choreography. If a trigger never fires -- a mis-measured
     * refresh, content that arrived late and shifted the page -- this is what
     * guarantees rule one: an operator can always read the screen.
     */
    function sweepStuck(targets) {
        const height = viewportHeight()

        targets.forEach((el) => {
            if (!el.isConnected) {
                return
            }

            const rect = el.getBoundingClientRect()
            const onScreen = rect.bottom > 0 && rect.top < height

            if (!onScreen || Number(gsap.getProperty(el, 'opacity')) >= 0.99) {
                return
            }

            gsap.to(el, {
                opacity: 1,
                y: 0,
                scale: 1,
                duration: MOTION.fast,
                ease: MOTION.easeSoft,
                overwrite: true,
                clearProps: 'opacity,transform,transformOrigin,clipPath,willChange,transition',
            })
        })
    }

    /**
     * @param {boolean} rich  full choreography, or the flat version for small
     *                        screens and touch where the extra layers cost more
     *                        than they say.
     * @param {WeakSet} floated  headers already owned by the parallax pass.
     */
    function buildReveal(rich, floated) {
        const targets = collectBelowFold()
        if (!targets.length) {
            return
        }

        // Elements that get the clip wipe. Tables are excluded on purpose: their
        // header row is sticky and their body scrolls horizontally, and clipping
        // an ancestor of both is asking for a repaint bug for no visual gain.
        const clipped = new WeakSet()

        // One write pass, after all measuring is done.
        gsap.set(targets, {
            opacity: 0,
            y: rich ? 34 : 18,
            scale: rich ? 0.985 : 1,
            // Scaling from the top edge keeps the card's own top in place, so a
            // column of cards does not visibly shuffle as each one lands.
            transformOrigin: '50% 0%',
            // motion.css puts `transition: transform 180ms` on .fi-section for
            // its hover lift. Left alone, the browser would re-interpolate every
            // frame GSAP writes, so the reveal would arrive ~180ms late and with
            // its easing smeared flat. Suppressed here and handed back by
            // clearProps the moment the tween lands, so hover still works.
            transition: 'none',
        })

        if (rich) {
            targets.forEach((el) => {
                if (el.matches('.fi-section, .fi-wi-widget')) {
                    clipped.add(el)
                    gsap.set(el, { clipPath: CLIP_FROM })
                }
            })
        }

        // batch() groups everything that crosses the line inside one interval
        // into a single tween. One trigger per element would mean dozens of
        // triggers on a report page, all recalculating on every refresh.
        ScrollTrigger.batch(targets, {
            interval: 0.1,
            batchMax: 4,
            start: 'top 92%',
            // Scrolling back up must not replay anything. These are cards an
            // operator is comparing, not slides.
            once: true,
            onEnter: (batch) => {
                const hurried = readVelocity() > HURRY_VELOCITY

                // will-change is promoted here rather than at prepare time so at
                // most a handful of layers exist at once, and it is dropped again
                // by clearProps when the tween lands.
                gsap.set(batch, { willChange: 'transform, opacity' })

                gsap.to(batch, {
                    opacity: 1,
                    y: 0,
                    scale: 1,
                    // Elements without a starting clip animate 'none' to 'none',
                    // which is a no-op -- this keeps the whole batch in one
                    // tween so the stagger stays a single reading order.
                    clipPath: (index, el) => (clipped.has(el) ? CLIP_TO : 'none'),
                    duration: hurried ? MOTION.fast : MOTION.base,
                    ease: MOTION.ease,
                    stagger: hurried ? MOTION.stagger : MOTION.stagger * 1.6,
                    overwrite: 'auto',
                    // Leave no inline residue: the card ends up exactly as the
                    // server rendered it, which is what keeps hover states and
                    // Filament's own Alpine styling working afterwards.
                    // transformOrigin has to be named separately -- clearing
                    // "transform" does not take it with it.
                    clearProps: 'opacity,transform,transformOrigin,clipPath,willChange,transition',
                })

                if (!rich || hurried) {
                    return
                }

                // Inner choreography: the card opens, and its contents settle
                // inside it a beat later. This is the difference between a card
                // appearing and a card being opened.
                const parts = []

                batch.forEach((el) => {
                    const header = el.querySelector(':scope > .fi-section-header')
                    const content = el.querySelector(':scope > .fi-section-content-ctn')

                    // A floated header is already being driven by a scrubbed
                    // tween; a second tween on the same y would fight it.
                    if (header && !floated.has(header)) {
                        parts.push(header)
                    }

                    if (content) {
                        parts.push(content)
                    }
                })

                if (!parts.length) {
                    return
                }

                gsap.from(parts, {
                    y: 12,
                    duration: MOTION.base,
                    ease: MOTION.ease,
                    stagger: 0.05,
                    delay: 0.06,
                    clearProps: 'transform',
                })
            },
        })

        // Two sweeps: one for the normal case, one late enough to catch content
        // Livewire deferred past the first.
        gsap.delayedCall(1.2, () => sweepStuck(targets))
        gsap.delayedCall(3.5, () => sweepStuck(targets))
    }

    /* ------------------------------------------------------------------ *
     * Wiring
     * ------------------------------------------------------------------ */

    buildProgressBar()

    const mm = gsap.matchMedia()
    disposers.push(() => mm.revert())

    // One branch, not two: a pair of complementary queries leaves a hole for
    // anything that reports neither `pointer: fine` nor `pointer: coarse`, and
    // on such a device no branch would run at all. `always` is a query that can
    // never fail, so the callback is guaranteed to build exactly once, and the
    // other two only decide how much it builds.
    //
    // matchMedia reverts a branch's work before re-running it, so a resize
    // across the desktop boundary cleans up after itself -- which is why the
    // element list is measured inside the callback rather than outside.
    mm.add(
        {
            always: '(min-width: 0px)',
            wide: '(min-width: 1024px)',
            fine: '(pointer: fine)',
        },
        (context) => {
            const conditions = context.conditions || {}

            // Parallax on a touch scroll is fighting a physics engine you do not
            // control, and a phone rendering a dashboard has better things to
            // composite. Those get the reveal and nothing else.
            const rich = Boolean(conditions.wide && conditions.fine)
            const floated = new WeakSet()

            if (rich) {
                // Order matters: the float claims its headers before the reveal
                // decides which ones it may also animate.
                buildSectionHeaderFloat(floated)
                buildHeaderParallax()
            }

            buildReveal(rich, floated)
        },
    )
}
