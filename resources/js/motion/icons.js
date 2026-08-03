/*
 * Icon motion
 * -----------
 * Filament renders every heroicon as inline SVG -- `generate_icon_html()` in
 * `filament/support/src/helpers.php` stamps `class="fi-icon fi-size-*"` onto the
 * glyph itself -- which means the individual paths are addressable and an icon
 * can do more than sit there. This module makes four of them move, and each one
 * is tied to something the panel has actually just done:
 *
 *   - A COLLAPSE CHEVRON springs as it flips, so opening a section reads as one
 *     gesture rather than a class swap.
 *   - A COPY BUTTON swaps its glyph for a check that draws itself on, holds for
 *     a beat, and draws back off. Other than a tooltip that appears somewhere
 *     else, this is the only confirmation a copy gets in this panel, and it is
 *     the one the operator's eye is already pointed at.
 *   - A STATUS ICON pops when its state genuinely changes -- when the CPU stat
 *     crosses out of success into danger, say. Never otherwise.
 *   - The large icons in EMPTY STATES and SECTION HEADERS draw themselves on,
 *     stroke first, the way they would have been drawn.
 *
 * Five things constrain all of it.
 *
 * 1. AN ICON MID-ANIMATION IS AN ICON THAT IS NOT THERE. A draw-on parks a path
 *    at `stroke-dashoffset: length`, which is indistinguishable from a missing
 *    icon, and the copy swap holds the real glyph at opacity 0. Both are claimed
 *    with a data attribute the instant they start; both settle on a wall clock
 *    that does not depend on GSAP finishing; both settle when the tab is hidden,
 *    because rAF stops there and tweens stop with it; and `heal()` below repairs
 *    anything still flagged at the top of the next run -- which is the case that
 *    actually matters, because by then the closure that knew how to fix it is
 *    long gone.
 *
 * 2. NO ANIMATION MAY IMPLY A STATE CHANGE THAT DID NOT HAPPEN. Status icons
 *    are most of the reason this panel exists. Nothing here animates one on a
 *    poll, on a re-render or on arrival -- only when its colour token or its
 *    glyph differs from the one recorded a moment earlier. Table cells are
 *    excluded outright: rows are destroyed and rebuilt every 30-60s, so "this is
 *    a different node" carries no information there at all.
 *
 * 3. FILAMENT ALREADY OWNS THE SEMANTIC HALF OF THE CHEVRON. Its stylesheet puts
 *    `rotate: 180deg` on a collapsed section's toggle and `-180deg` on a
 *    collapsed sidebar group's. That stays exactly where it is: a chevron
 *    pointing the wrong way is a lie about whether a block is open, and no
 *    failure in this file may be able to cause one. The injected stylesheet only
 *    lengthens the flip from Filament's 75ms to something an eye can follow, and
 *    GSAP springs the glyph *inside* the button -- a rotation that always
 *    resolves to zero, so the resting state is never ours to get wrong.
 *
 * 4. NEVER TWEEN THE BUTTON, ONLY THE GLYPH. Two independent reasons, both
 *    non-obvious. GSAP folds an element's computed `rotate`/`scale`/`translate`
 *    into `transform` and writes `rotate: none` inline as it does so -- run it
 *    on a collapse button and Filament's flip is dead for the life of the page.
 *    And GSAP writes SVG transforms to the `transform` ATTRIBUTE for anything
 *    with an `ownerSVGElement`, which would silently overwrite the attribute the
 *    check glyph below uses to map itself onto the host icon's viewBox. A root
 *    `<svg>` has no `ownerSVGElement`, so it gets an ordinary CSS transform;
 *    that is the only element in this file that is ever transformed, and its
 *    paths only ever get opacity and dash properties.
 *
 * 5. TRANSFORMS ARE TRANSIENT. Filament renders action modals inline, inside the
 *    section that owns them, and an element holding a live transform becomes the
 *    containing block for its `position: fixed` descendants. Every transform
 *    here lives on an <svg> that contains no controls, and is cleared the moment
 *    its tween lands.
 *
 * Cleanup: gsap.context() reverts tweens, not appended nodes, listeners,
 * observers, timers or <style> tags. A nested context is opened purely so the
 * runtime's revert reaches the disposer that removes all of them.
 */

/* The injected stylesheet. Removed and rebuilt on every run. */
const STYLE_ID = 'vf-icons'

/* Marks the one node this module ever appends into Filament's markup: the check
   path of a copy confirmation. Swept by attribute, so an interrupted run cannot
   leave one behind. */
const OWNED = 'data-vf-icon'

/* An <svg> whose real glyph is currently hidden behind that check. */
const SWAPPED = 'data-vf-icon-swapped'

/* An <svg> whose transform, opacity and transition GSAP currently owns. */
const LIVE = 'data-vf-icon-live'

/* A <path> currently clipped by a dash offset -- a draw-on in flight. The most
   dangerous state in this file, because the path is invisible while it holds.
   The value is whatever dash attributes the asset itself declared, so a
   *different* boot can put them back byte for byte. */
const DRAWING = 'data-vf-icon-drawing'

/* One-shot claim on a draw-on target. A section header icon draws once per page;
   a Livewire poll that re-renders the section must not redraw it. */
const DRAWN = 'data-vf-icon-drawn'

/* heroicons' `check`, in the 24x24 grid every heroicon is authored in. Mapped
   onto the host icon's own viewBox at build time so mini (20) and micro (16)
   glyphs get the same mark without a second asset. */
const CHECK_D = 'm4.5 12.75 6 6 9-13.5'
const CHECK_GRID = 24

const SVG_NS = 'http://www.w3.org/2000/svg'

/* Filament's icon element. `.fi-loading-indicator` shares the class and is a
   spinner that animates itself, so it is excluded everywhere. */
const GLYPH = 'svg.fi-icon:not(.fi-loading-indicator)'

/* Containers whose descendants are rebuilt wholesale on every table poll.
   Nothing in here may treat a node inside one as having a stable identity. */
const VOLATILE = '.fi-ta-ctn, .fi-ta-table, .fi-ta-content-ctn'

/* Draw-on targets, verified against
   `support/resources/views/components/section/index.blade.php` (the header icon
   is a direct child of `.fi-section-header`, so the `>` also excludes the
   collapse button's chevron),
   `support/resources/views/components/empty-state.blade.php`, and
   `tables/resources/views/index.blade.php`, which has its own
   `.fi-ta-empty-state-icon-bg` rather than reusing the shared component. */
const DRAW_TARGETS = [
    `.fi-empty-state-icon-bg > ${GLYPH}`,
    `.fi-ta-empty-state-icon-bg > ${GLYPH}`,
    `.fi-main .fi-section > .fi-section-header > ${GLYPH}`,
].join(', ')

/* Collapsibles whose toggle carries a chevron. Both flip via a `fi-collapsed`
   class on the block, applied by Alpine -- see the `x-bind:class` in
   `section/index.blade.php` and `filament/.../components/sidebar/group.blade.php`.
   Table record and group toggles collapse the same way but live inside rows, so
   they are deliberately absent. */
const COLLAPSIBLES = '.fi-section.fi-collapsible, .fi-sidebar-group.fi-collapsible'

/* Where the chevron sits inside each of those. Note `-btn`, not `-button`. */
const CHEVRONS = '.fi-section-collapse-btn, .fi-sidebar-group-collapse-btn'

/* Roots watched for a genuine status change. `.fi-wi-stats-overview` is the
   panel's live health read-out and the one place an icon's meaning changes under
   the operator; `[data-vf-status]` lets a Blade view opt anything else in.
   Roots, not the icons themselves, because Livewire's morph can replace an inner
   node outright and an observer pointed at a detached element is an observer
   that has quietly stopped. */
const STATUS_ROOTS = '.fi-wi-stats-overview, [data-vf-status]'

/* The colour-bearing wrappers inside those roots.
   `.fi-wi-stats-overview-stat-description` carries the `fi-color-*` token that
   turns amber then red as a threshold is crossed (see `ServerHealthStats`);
   `.fi-in-icon` is an infolist icon entry, whose glyph itself swaps. */
const STATUS_CARRIERS = '.fi-wi-stats-overview-stat-description, .fi-in-icon'

/* Ceilings. A page with more section headers than this is a list, not a
   composition, and drawing all of them would cost more than it says. */
const MAX_DRAWS = { high: 14, medium: 6, low: 0 }
const MAX_COLLAPSIBLES = 30
const MAX_STATUS_ROOTS = 8
const MAX_STATUS_CARRIERS = 24

/* A status icon may not re-pop inside this window, in ms. Guards against a burst
   of morphs reading as a flurry of state changes. */
const STATUS_COOLDOWN = 1200

/* How long the copy check holds before drawing back off, in seconds. Long enough
   to be seen; short enough that the button is not still claiming to be
   mid-copy. */
const CHECK_HOLD = 1

const CSS = `
/* Filament flips a collapsed section's chevron with \`rotate: 180deg\` and a
   collapsed sidebar group's with \`-180deg\`, on the 75ms transition every
   \`.fi-icon-btn\` carries. That flip stays exactly where it is -- it is the
   thing that says whether the block is open, and nothing in this file may be
   able to leave it pointing the wrong way. Only its timing changes here. The
   per-property lists keep every other transition on the button at Filament's
   75ms, so hover colour still snaps while the rotation gets a curve an eye can
   follow. */
.fi-section.fi-collapsible > .fi-section-header > .fi-section-collapse-btn,
.fi-sidebar-group.fi-collapsible .fi-sidebar-group-collapse-btn {
    transition-property: rotate, color, background-color, opacity, box-shadow;
    transition-duration: 340ms, 75ms, 75ms, 75ms, 75ms;
    transition-timing-function: var(--vf-ease-io, cubic-bezier(0.65, 0, 0.35, 1)), ease, ease, ease, ease;
}

/* A stat's description colour IS its status: gray while the reading is
   unavailable, then success, warning and danger as thresholds are crossed.
   Filament transitions neither the wrapper nor its icon, so a poll that pushes
   CPU load past one per core repaints the icon red between two frames and reads
   as a glitch rather than as an event. Three hundred milliseconds turns it into
   the crossfade it should have been. Selector depth matches Filament's own so
   the colour rules it sets on these same elements still win. */
.fi-wi-stats-overview-stat .fi-wi-stats-overview-stat-description,
.fi-wi-stats-overview-stat .fi-wi-stats-overview-stat-description .fi-icon,
.fi-in-icon .fi-icon {
    transition-property: color;
    transition-duration: 300ms;
    transition-timing-function: var(--vf-ease-soft, cubic-bezier(0.33, 1, 0.68, 1));
}
`

/* ------------------------------------------------------------------------- */
/* Healing                                                                    */
/* ------------------------------------------------------------------------- */

/**
 * Put back anything left mid-flight, without needing the closure that started
 * it.
 *
 * Runs at the top of every boot, on teardown, on navigation and whenever the tab
 * is hidden. Driven entirely off attributes in the DOM, so a run interrupted by
 * a navigation, by a crash in another module, or by the frame budget going away
 * still ends with every icon visible. Idempotent, because several of those paths
 * overlap.
 */
function heal() {
    // Our own nodes first, so a half-drawn check cannot survive the restore
    // below and end up sitting on top of a glyph that just came back.
    document.querySelectorAll(`[${OWNED}]`).forEach((node) => node.remove())

    document.querySelectorAll(`[${SWAPPED}]`).forEach((svg) => {
        for (const child of svg.children) {
            child.style.removeProperty('opacity')
        }

        svg.removeAttribute(SWAPPED)
    })

    document.querySelectorAll(`[${LIVE}]`).forEach((svg) => {
        const style = svg.style

        style.removeProperty('opacity')
        style.removeProperty('transform')
        style.removeProperty('transform-origin')
        style.removeProperty('transition')
        style.removeProperty('will-change')

        // GSAP folds the independent transform properties into `transform` and
        // writes these three as `none` while it owns an element. Left behind,
        // they outrank any stylesheet that later wants to use them.
        style.removeProperty('translate')
        style.removeProperty('rotate')
        style.removeProperty('scale')

        svg.removeAttribute(LIVE)
    })

    document.querySelectorAll(`[${DRAWING}]`).forEach(restoreDash)
}

/**
 * Hand a path back to whatever its SVG asset declared.
 *
 * The dash values are written as inline styles, so removing them exposes the
 * original presentation attributes untouched -- but heroicons ship no dash at
 * all, and an icon that did would be quietly broken by a blanket removal. The
 * originals are therefore parked on the element when the draw starts, which is
 * what lets a completely different boot restore them.
 */
function restoreDash(path) {
    const memo = path.getAttribute(DRAWING) || ''
    const style = path.style

    style.removeProperty('stroke-dasharray')
    style.removeProperty('stroke-dashoffset')
    style.removeProperty('opacity')
    style.removeProperty('transition')

    const [array, offset] = memo.split('|')

    if (array) {
        path.setAttribute('stroke-dasharray', array)
    }

    if (offset) {
        path.setAttribute('stroke-dashoffset', offset)
    }

    path.removeAttribute(DRAWING)
}

/* ------------------------------------------------------------------------- */
/* Lifecycle helpers                                                          */
/* ------------------------------------------------------------------------- */

/**
 * A bag of teardown callbacks. Every listener, observer, timer and stylesheet in
 * this file goes through one so nothing survives a context revert.
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
        timer(fn, ms) {
            const id = window.setTimeout(fn, ms)
            disposers.push(() => window.clearTimeout(id))
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
                    console.warn('[vpn-forge motion] icons cleanup failed', error)
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
}

/* ------------------------------------------------------------------------- */
/* Reading the DOM                                                            */
/* ------------------------------------------------------------------------- */

/** The icon inside a wrapper, or null. Never the loading spinner. */
function glyphIn(element) {
    if (!element) {
        return null
    }

    if (element.matches && element.matches(GLYPH)) {
        return element
    }

    return element.querySelector ? element.querySelector(GLYPH) : null
}

/**
 * Whether an icon is drawn with a stroke rather than filled.
 *
 * Read off the attributes, never the computed style: `fill` is an inherited
 * presentation attribute, so an outline heroicon sitting in coloured markup
 * still computes to a real colour and would be misread as filled. Outline
 * heroicons ship `fill="none" stroke="currentColor"`; solid, mini and micro ship
 * `fill="currentColor"` and no stroke at all, and have no outline to travel
 * along.
 */
function isStroked(svg) {
    return svg.getAttribute('fill') === 'none' && Boolean(svg.getAttribute('stroke'))
}

/**
 * The paths of one icon that can actually be drawn, with their lengths.
 *
 * Every measurement for a given icon happens here, before any of its paths is
 * written to, so the geometry is resolved once rather than once per path.
 */
function measurablePaths(svg, limit) {
    const paths = svg.querySelectorAll('path')
    const measured = []

    for (let i = 0; i < paths.length && measured.length < limit; i += 1) {
        const path = paths[i]
        let length = 0

        try {
            // Throws in some engines on a malformed `d`, and returns 0 for a
            // path that is present but empty.
            length = path.getTotalLength()
        } catch (error) {
            length = 0
        }

        if (Number.isFinite(length) && length > 0.5) {
            measured.push({ path, length })
        }
    }

    return measured
}

/** On screen, in a tab someone is actually looking at. */
/**
 * Below this, a stroke draw does not read as drawing -- it reads as a broken
 * icon.
 *
 * A section-header heroicon is 20-24px. At that size the dash walk covers a few
 * pixels per frame, and because only the first few sub-paths are measured, an
 * icon with more of them shows some strokes missing while the rest are already
 * solid. On the getting-started checklist that rendered as a half-drawn glyph
 * sitting next to three complete ones. Empty-state icons are several times
 * larger and are where the effect actually belongs.
 */
const MIN_DRAW_SIZE = 40

function isBigEnoughToDraw(svg) {
    const rect = svg.getBoundingClientRect()

    return Math.min(rect.width, rect.height) >= MIN_DRAW_SIZE
}

function isWatchable(element) {
    if (document.visibilityState === 'hidden' || !element.isConnected) {
        return false
    }

    const rect = element.getBoundingClientRect()

    if (!rect.width && !rect.height) {
        return false
    }

    return rect.bottom > 0 && rect.top < (window.innerHeight || 0)
}

/**
 * Run `enter` the first time `element` is on screen, and only then.
 *
 * 95% of the viewport rather than the usual two thirds: a draw-on's opening
 * frame is an icon that is not there, so that state is only ever adopted for an
 * element within a hair of being visible.
 */
function onFirstView(ScrollTrigger, element, enter) {
    if (!ScrollTrigger) {
        enter()

        return
    }

    ScrollTrigger.create({ trigger: element, start: 'top 95%', once: true, onEnter: enter })
}

/* ------------------------------------------------------------------------- */
/* Entry point                                                                */
/* ------------------------------------------------------------------------- */

/**
 * @param {{gsap: import('gsap').gsap, ScrollTrigger: object, MOTION: object}} api
 * @returns {null}
 */
export function iconMotion({ gsap, ScrollTrigger, MOTION }) {
    if (typeof document === 'undefined' || !document.body) {
        return null
    }

    // Anything a previous run left mid-draw or mid-swap goes back to normal
    // before a single new tween exists.
    heal()

    const verdict = window.__vfMotion || {}
    const tier = verdict.tier ?? 'medium'

    // The runtime already skips every module under a reduced-motion preference.
    // This is the second reading, in case the governor ever knows something the
    // media query does not.
    if (verdict.reduced) {
        return null
    }

    const scope = createScope()

    // gsap.context() reverts tweens, not observers, listeners, timers or
    // appended nodes. This nested context exists only so the runtime's revert()
    // reaches the disposer below.
    gsap.context(() => () => {
        scope.dispose()
        heal()
    })

    /*
     * `gsap.context()` with no argument hands back the context we are currently
     * running inside -- the runtime's. Tweens created later, from a click
     * handler or an observer callback, would otherwise be born with no context
     * at all: nothing would revert them, and the inline styles they left behind
     * would survive the next navigation frozen mid-animation.
     */
    const host = gsap.context()

    /**
     * Run `fn` as part of the runtime's context and hand back its result. The
     * result travels out through a closure rather than a return value because
     * `context.add()` treats a returned *function* as a cleanup callback and
     * would invoke it on revert.
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

    injectStylesheet(scope)

    const api = { gsap, ScrollTrigger, MOTION, scope, owned, tier }

    // Hiding the tab stops rAF, which stops GSAP, which would leave a draw-on or
    // a copy swap frozen on its invisible frame until the operator came back.
    // Settle everything the moment the frame budget goes away.
    scope.on(document, 'visibilitychange', () => {
        if (document.visibilityState === 'hidden') {
            heal()
        }
    })

    // A navigation tears this module down anyway, but only after the next page
    // has started rendering. Healing on the way out means no icon is ever
    // carried across mid-animation.
    scope.on(document, 'livewire:navigating', heal)

    copyConfirmation(api)
    collapseChevrons(api)

    // Everything below either measures SVG geometry or holds an observer. On the
    // weakest tier the panel gets the two click-driven effects above and nothing
    // else.
    if (tier !== 'low') {
        statusChanges(api)
        drawOn(api)
    }

    return null
}

/* ------------------------------------------------------------------------- */
/* Copy confirmation                                                          */
/* ------------------------------------------------------------------------- */

/**
 * Clipboard writes in this panel are fire and forget. Filament's `copyable()`
 * calls `navigator.clipboard.writeText()` straight out of an Alpine
 * `x-on:click` and shows a tooltip; the panel's own copy button on the Updates
 * page does the same from its own `x-data`. There is no success event to listen
 * for and no promise to hook, so the confirmation is driven off the click --
 * which is what every copy button in the world does, and which stays honest as
 * long as the mark is transient. It is: it draws on, holds for a second, draws
 * off, and the node is gone.
 */
function copyConfirmation({ gsap, MOTION, scope, owned }) {
    /* Anything that could carry the mark. `.fi-copyable` is Filament's own --
       see `tables/src/Columns/TextColumn.php`, `infolists/src/Components/TextEntry.php`
       and `schemas/src/Components/Text.php`, which all add it alongside the
       clipboard handler. */
    const CANDIDATE = '.fi-btn, .fi-icon-btn, .fi-copyable, [data-vf-copy]'

    /**
     * Whether a control copies something.
     *
     * Matched on intent, not on iconography: blade-icons renders a heroicon with
     * its name nowhere in the output, so there is nothing to compare a clipboard
     * glyph against. What there *is* in the DOM is the handler doing the
     * copying. Alpine and Livewire leave their bindings in the markup, modifiers
     * and all -- `x-on:click.prevent.stop`, `wire:click.debounce.500ms` -- so
     * the attribute names are matched by prefix rather than looked up.
     */
    const copies = (element) => {
        if (element.hasAttribute('data-vf-copy') || element.classList.contains('fi-copyable')) {
            return true
        }

        if (/copy/i.test(element.id || '')) {
            return true
        }

        for (const attribute of element.attributes) {
            const name = attribute.name

            if (
                (name.startsWith('x-on:click') ||
                    name.startsWith('@click') ||
                    name.startsWith('wire:click')) &&
                /clipboard|copy/i.test(attribute.value)
            ) {
                return true
            }
        }

        return false
    }

    /** Swap an icon's glyph for a check that draws itself on, then back. */
    const confirm = (svg) => {
        if (svg.hasAttribute(SWAPPED) || svg.hasAttribute(LIVE)) {
            return
        }

        const glyph = Array.from(svg.children)

        // Nothing to swap out means nothing to swap back in, and an empty GSAP
        // target list is a warning in the console for no benefit.
        if (!glyph.length) {
            return
        }

        const check = buildCheck(svg)

        if (!check) {
            return
        }

        // Appended before measuring: a detached path reports no length in some
        // engines, and this is the only read the whole effect needs.
        svg.appendChild(check)

        let length = 0

        try {
            length = check.getTotalLength()
        } catch (error) {
            length = 0
        }

        if (!Number.isFinite(length) || length <= 0.5) {
            check.remove()

            return
        }

        svg.setAttribute(SWAPPED, '')
        svg.setAttribute(LIVE, '')

        let settled = false

        /** Every path out of the sequence ends here, and only the first counts. */
        const settle = () => {
            if (settled) {
                return
            }

            settled = true
            check.remove()

            if (!svg.isConnected) {
                return
            }

            glyph.forEach((child) => child.style.removeProperty('opacity'))
            svg.removeAttribute(SWAPPED)
            svg.removeAttribute(LIVE)
            gsap.set(svg, {
                clearProps: 'opacity,transform,transformOrigin,transition,willChange',
            })
        }

        // The real glyph sits at opacity 0 for the length of this sequence, so
        // its return may not depend on GSAP running to completion. This is the
        // wall clock; it no-ops if the timeline already finished, and the scope
        // cancels it if the page goes away first.
        scope.timer(settle, (0.4 + CHECK_HOLD + 0.4) * 1000 + 400)

        owned(() => {
            const tl = gsap.timeline({ onComplete: settle, onInterrupt: settle })

            tl
                // Dash state is set rather than tweened from, so the check's
                // first painted frame is already empty instead of a fully drawn
                // tick that then unwinds. Units are explicit: inside an SVG one
                // CSS px is one user unit, which is what getTotalLength returned.
                .set(check, {
                    strokeDasharray: `${length}px`,
                    strokeDashoffset: `${length}px`,
                })
                // Several of Filament's scoped `.fi-icon` rules transition
                // transform at 75ms. Left in place the browser re-interpolates
                // every frame GSAP writes and the spring smears flat. An inline
                // value outranks any of them; clearProps hands the glyph back.
                .set(svg, {
                    transition: 'none',
                    transformOrigin: '50% 50%',
                    willChange: 'transform',
                })
                // The glyph leaves before the mark arrives, so the two never
                // overlap into an unreadable smudge.
                .to(glyph, { opacity: 0, duration: 0.1, ease: 'power2.in' }, 0)
                .to(svg, { scale: 0.82, duration: 0.1, ease: 'power2.in' }, 0)
                .to(check, { strokeDashoffset: '0px', duration: 0.24, ease: MOTION.easeSoft }, 0.07)
                .to(svg, { scale: 1, duration: 0.28, ease: MOTION.spring }, 0.1)
                // Hold, then unwind in the same order. `+=` is relative to the
                // end of the timeline as it stands, which is the settle above.
                .to(
                    check,
                    { strokeDashoffset: `${length}px`, duration: 0.16, ease: 'power2.in' },
                    `+=${CHECK_HOLD}`,
                )
                .to(glyph, { opacity: 1, duration: 0.18, ease: MOTION.easeSoft }, '-=0.04')

            return tl
        })
    }

    scope.on(
        document,
        'click',
        (event) => {
            const target = event.target

            if (!target || !target.closest) {
                return
            }

            const control = target.closest(CANDIDATE)

            if (!control || !copies(control)) {
                return
            }

            // A confirmation nobody can see is a node appended into Livewire's
            // markup for nothing.
            if (!isWatchable(control)) {
                return
            }

            // The copyable element is often the text itself, which carries no
            // icon of its own; the mark then belongs on the button wrapping it.
            const svg = glyphIn(control) || glyphIn(control.closest('.fi-btn, .fi-icon-btn'))

            if (svg) {
                confirm(svg)
            }
        },
        { passive: true },
    )
}

/**
 * A check path mapped from heroicons' 24x24 authoring grid onto whatever viewBox
 * the host icon uses, so a mini (20) or micro (16) glyph gets the same mark at
 * the same visual weight without shipping a second asset.
 *
 * `fill` and `stroke` are stated on the path rather than inherited: the host
 * <svg> declares `fill="currentColor"` for a solid icon and `fill="none"` for an
 * outline one, and the check has to stroke in both cases. The stroke width is
 * authored in the 24-grid alongside the path, so the transform scales the weight
 * with the glyph instead of leaving a hairline on a mini icon.
 */
function buildCheck(svg) {
    const raw = (svg.getAttribute('viewBox') || '').trim().split(/[\s,]+/).map(Number)
    const box = raw.length === 4 && raw.every((n) => Number.isFinite(n)) ? raw : [0, 0, 24, 24]
    const [minX, minY, width, height] = box

    if (!(width > 0) || !(height > 0)) {
        return null
    }

    const path = document.createElementNS(SVG_NS, 'path')

    path.setAttribute(OWNED, 'check')
    path.setAttribute('d', CHECK_D)
    path.setAttribute(
        'transform',
        `translate(${minX} ${minY}) scale(${width / CHECK_GRID} ${height / CHECK_GRID})`,
    )
    path.setAttribute('fill', 'none')
    path.setAttribute('stroke', 'currentColor')
    path.setAttribute('stroke-width', '2.2')
    path.setAttribute('stroke-linecap', 'round')
    path.setAttribute('stroke-linejoin', 'round')

    return path
}

/* ------------------------------------------------------------------------- */
/* Collapse chevrons                                                          */
/* ------------------------------------------------------------------------- */

/**
 * A spring on the glyph inside a collapse toggle, layered over the 180deg flip
 * the stylesheet performs.
 *
 * Driven off the `fi-collapsed` class rather than off a click, because that is
 * the actual state: sections also collapse from `collapse-section` window
 * events and from a persisted Alpine store on load, and a click handler would
 * miss both while firing on clicks that toggled nothing.
 *
 * The glyph's own rotation always resolves to zero, so the resting state stays
 * whatever Filament's stylesheet says it is. This can add character to the flip;
 * it cannot leave a chevron pointing the wrong way.
 */
function collapseChevrons({ gsap, MOTION, scope, owned }) {
    if (typeof MutationObserver !== 'function') {
        return
    }

    const blocks = Array.from(document.querySelectorAll(COLLAPSIBLES))
        .filter((block) => !block.closest(VOLATILE))
        .slice(0, MAX_COLLAPSIBLES)

    if (!blocks.length) {
        return
    }

    /* Last known open/closed state per block. A `class` mutation fires for every
       class Alpine touches, so this flag is what says whether this particular
       one changed anything. */
    const state = new WeakMap()

    blocks.forEach((block) => state.set(block, block.classList.contains('fi-collapsed')))

    const spring = (block) => {
        const svg = glyphIn(block.querySelector(CHEVRONS))

        if (!svg || !isWatchable(svg)) {
            return
        }

        svg.setAttribute(LIVE, '')

        const release = () => {
            svg.removeAttribute(LIVE)
            gsap.set(svg, { clearProps: 'transform,transformOrigin,transition' })
        }

        owned(() =>
            gsap.fromTo(
                svg,
                { rotation: -20, scale: 0.78, transition: 'none' },
                {
                    rotation: 0,
                    scale: 1,
                    // Overshoot deliberately: the glyph passes zero and comes
                    // back, which on top of the stylesheet's eased 180deg reads
                    // as the chevron snapping into its new position.
                    duration: 0.34,
                    ease: MOTION.spring,
                    transformOrigin: '50% 50%',
                    overwrite: 'auto',
                    onComplete: release,
                    onInterrupt: release,
                },
            ),
        )
    }

    const observer = new MutationObserver((records) => {
        for (const record of records) {
            const block = record.target
            const collapsed = block.classList.contains('fi-collapsed')

            if (state.get(block) === collapsed) {
                continue
            }

            state.set(block, collapsed)
            spring(block)
        }
    })

    // No subtree: the class we care about is on the block itself, and a section
    // full of table rows would otherwise report on every poll.
    blocks.forEach((block) =>
        observer.observe(block, { attributes: true, attributeFilter: ['class'] }),
    )

    scope.add(() => observer.disconnect())
}

/* ------------------------------------------------------------------------- */
/* Status changes                                                             */
/* ------------------------------------------------------------------------- */

/**
 * A status icon acknowledging that its state changed -- and nothing else.
 *
 * This is the one effect in the file that could actively mislead. An operator
 * reading the health widget has to be able to trust that a moving icon means
 * something moved, so the trigger is a comparison rather than an event: the
 * colour token and the glyph are fingerprinted, and an icon animates only when
 * the new fingerprint differs from the one recorded a moment ago. A poll that
 * returns the same reading re-renders the widget and produces nothing at all.
 */
function statusChanges({ gsap, MOTION, scope, owned }) {
    if (typeof MutationObserver !== 'function') {
        return
    }

    const roots = Array.from(document.querySelectorAll(STATUS_ROOTS))
        .filter((root) => !root.closest(VOLATILE))
        .slice(0, MAX_STATUS_ROOTS)

    if (!roots.length) {
        return
    }

    /* Keyed by element, so a carrier Livewire replaces simply starts fresh
       rather than inheriting a stale reading from the node it replaced. */
    const seen = new WeakMap()
    const lastPop = new WeakMap()

    /**
     * What an icon currently says: its colour token and the opening of its path
     * data. Between them these catch both kinds of change this panel produces --
     * a threshold crossing that only recolours a stat, and an infolist entry
     * that swaps one glyph for another.
     */
    const fingerprint = (carrier) => {
        const parts = []
        const own = (carrier.getAttribute('class') || '').match(/\bfi-color-[\w-]+/g)

        if (own) {
            parts.push(own.join(' '))
        }

        const svg = glyphIn(carrier)

        if (svg) {
            const tint = (svg.getAttribute('class') || '').match(/\bfi-color-[\w-]+/g)

            if (tint) {
                parts.push(tint.join(' '))
            }

            const paths = svg.querySelectorAll('path')

            for (let i = 0; i < paths.length && i < 3; i += 1) {
                parts.push((paths[i].getAttribute('d') || '').slice(0, 48))
            }
        }

        return parts.join('|')
    }

    const pop = (svg) => {
        const now = performance.now()

        if (now - (lastPop.get(svg) || 0) < STATUS_COOLDOWN) {
            return
        }

        lastPop.set(svg, now)
        svg.setAttribute(LIVE, '')

        const release = () => {
            svg.removeAttribute(LIVE)
            gsap.set(svg, {
                clearProps: 'opacity,transform,transformOrigin,transition,willChange',
            })
        }

        owned(() =>
            gsap.fromTo(
                svg,
                // Never all the way to zero: the icon dims and turns rather than
                // vanishing, so at no point in the transition is the panel
                // showing an absent status.
                { opacity: 0.25, scale: 0.6, rotation: -18, transition: 'none' },
                {
                    opacity: 1,
                    scale: 1,
                    rotation: 0,
                    duration: 0.36,
                    ease: MOTION.spring,
                    transformOrigin: '50% 50%',
                    overwrite: 'auto',
                    onComplete: release,
                    onInterrupt: release,
                },
            ),
        )
    }

    /**
     * Re-read every carrier under the observed roots and animate the ones whose
     * reading changed.
     *
     * Collected fresh rather than cached: Livewire's morph can replace a carrier
     * outright, and a cached list would be holding detached nodes from the first
     * poll onwards.
     */
    const reconcile = () => {
        const visible = document.visibilityState === 'visible'
        let budget = MAX_STATUS_CARRIERS

        for (const root of roots) {
            if (!root.isConnected || budget <= 0) {
                continue
            }

            let carriers = Array.from(root.querySelectorAll(STATUS_CARRIERS))

            // An opted-in root with no recognised carrier inside it is itself
            // the thing being watched.
            if (!carriers.length && root.hasAttribute('data-vf-status')) {
                carriers = [root]
            }

            for (const carrier of carriers) {
                if (budget <= 0) {
                    break
                }

                budget -= 1

                const next = fingerprint(carrier)
                const previous = seen.get(carrier)

                seen.set(carrier, next)

                // A first sighting is not a change. Neither is a change nobody
                // was there to see -- the fingerprint is recorded either way, so
                // the panel does not pop everything at once when a backgrounded
                // tab is focused again.
                if (previous === undefined || previous === next || !visible) {
                    continue
                }

                const svg = glyphIn(carrier)

                if (svg && isWatchable(svg)) {
                    pop(svg)
                }
            }
        }
    }

    // Baseline, so the first poll after boot has something to compare against.
    reconcile()

    let frame = 0

    const schedule = () => {
        if (frame) {
            return
        }

        // One pass per frame at most: a Livewire morph produces a burst of
        // mutations for what is, to us, a single new reading.
        frame = requestAnimationFrame(() => {
            frame = 0
            reconcile()
        })
    }

    scope.add(() => {
        if (frame) {
            cancelAnimationFrame(frame)
        }

        frame = 0
    })

    const observer = new MutationObserver(schedule)

    roots.forEach((root) =>
        observer.observe(root, {
            attributes: true,
            attributeFilter: ['class'],
            childList: true,
            subtree: true,
        }),
    )

    scope.add(() => observer.disconnect())
}

/* ------------------------------------------------------------------------- */
/* Draw-on                                                                    */
/* ------------------------------------------------------------------------- */

/**
 * The large icons that head a section or an empty state, drawn rather than faded
 * in.
 *
 * DrawSVG is club-only, so this is the trick that plugin performs, done by hand:
 * a path's total length becomes its dash pattern *and* its dash offset, which
 * puts the whole stroke in a gap, and animating the offset to zero walks the
 * stroke into existence. It only works on a stroke -- a filled glyph has no
 * outline to travel along and would simply not be there -- so solid, mini and
 * micro heroicons get a scale-in instead.
 *
 * The dangerous half is that a path parked at full offset is an invisible icon.
 * Nothing is prepared ahead of time: the dash is written in the same call that
 * starts the tween, only for an element already on screen, and a wall-clock
 * timer restores it whether or not GSAP ever gets there.
 */
function drawOn({ gsap, ScrollTrigger, MOTION, scope, owned, tier }) {
    const budget = MAX_DRAWS[tier] ?? MAX_DRAWS.medium

    if (budget <= 0) {
        return
    }

    const icons = Array.from(document.querySelectorAll(DRAW_TARGETS)).filter(
        (svg) => !svg.hasAttribute(DRAWN) && !svg.closest(VOLATILE) && isBigEnoughToDraw(svg),
    )

    if (!icons.length) {
        return
    }

    icons.slice(0, budget).forEach((svg, index) => {
        // Claimed up front, in document order, so a Livewire re-render of the
        // section cannot hand the same icon a second draw.
        svg.setAttribute(DRAWN, '')

        // Enough of a beat for the entrance and scroll modules to have finished
        // moving the card this icon sits on, so the draw reads as the last step
        // of that card arriving rather than as a competing animation.
        const delay = 0.24 + Math.min(index, 8) * MOTION.stagger
        const duration = 0.38

        onFirstView(ScrollTrigger, svg, () => {
            if (!svg.isConnected || !isWatchable(svg)) {
                return
            }

            if (isStroked(svg)) {
                drawStroke({ gsap, MOTION, scope, owned }, svg, delay, duration)
            } else {
                scaleIn({ gsap, MOTION, scope, owned }, svg, delay, duration)
            }
        })
    })
}

/** Walk an outline icon's strokes into existence. */
function drawStroke({ gsap, MOTION, scope, owned }, svg, delay, duration) {
    const drawable = svg.querySelectorAll('path').length
    const measured = measurablePaths(svg, 4)

    if (!measured.length) {
        return
    }

    // All or nothing. Only the measured paths get hidden behind a dash gap, so
    // an icon with more sub-paths than the cap would show the unmeasured ones
    // solid while the rest walked in -- which does not read as drawing, it reads
    // as an icon missing pieces. If the whole mark cannot be drawn, leave it
    // alone.
    if (drawable > measured.length) {
        return
    }

    let settled = false

    const settle = () => {
        if (settled) {
            return
        }

        settled = true
        measured.forEach(({ path }) => restoreDash(path))
    }

    // The icon is genuinely absent between here and settle(), so settle() runs
    // on the wall clock regardless of what GSAP does. Registered on the scope so
    // a navigation cancels it instead of it firing into a dead page.
    scope.timer(settle, (delay + duration) * 1000 + 400)

    owned(() => {
        const tl = gsap.timeline({ delay, onComplete: settle, onInterrupt: settle })

        measured.forEach(({ path, length }, index) => {
            // Whatever the asset declared is parked on the element itself, so a
            // completely different boot can put it back -- the closure holding
            // these values is gone by then.
            path.setAttribute(
                DRAWING,
                `${path.getAttribute('stroke-dasharray') || ''}|${path.getAttribute('stroke-dashoffset') || ''}`,
            )

            tl.fromTo(
                path,
                {
                    // px, explicitly: inside an SVG one CSS px is one user unit,
                    // which is the unit getTotalLength just reported.
                    strokeDasharray: `${length}px`,
                    strokeDashoffset: `${length}px`,
                    transition: 'none',
                },
                {
                    strokeDashoffset: '0px',
                    duration,
                    // No overshoot here, unlike the rest of this file: a dash
                    // offset that swings past zero draws the far end of the
                    // stroke back off again.
                    ease: MOTION.easeSoft,
                },
                // A two-path icon draws its second stroke just behind the first,
                // which is the order the mark would be drawn by hand.
                index * 0.07,
            )
        })

        return tl
    })
}

/** A filled icon has no outline to travel along, so it arrives instead. */
function scaleIn({ gsap, MOTION, scope, owned }, svg, delay, duration) {
    let settled = false

    const settle = () => {
        if (settled) {
            return
        }

        settled = true

        if (!svg.isConnected) {
            return
        }

        svg.removeAttribute(LIVE)
        gsap.set(svg, {
            clearProps: 'opacity,transform,transformOrigin,transition,willChange',
        })
    }

    svg.setAttribute(LIVE, '')
    scope.timer(settle, (delay + duration) * 1000 + 400)

    owned(() =>
        gsap.fromTo(
            svg,
            { opacity: 0, scale: 0.62, rotation: -8, transition: 'none' },
            {
                opacity: 1,
                scale: 1,
                rotation: 0,
                delay,
                duration,
                ease: MOTION.spring,
                transformOrigin: '50% 50%',
                overwrite: 'auto',
                onComplete: settle,
                onInterrupt: settle,
            },
        ),
    )
}
