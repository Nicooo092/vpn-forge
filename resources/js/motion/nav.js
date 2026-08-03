/**
 * Navigation choreography.
 * ------------------------
 * The sidebar is the one surface an operator touches on every page, and it is
 * also the only piece of chrome in this panel that CHANGES SHAPE: it collapses
 * to a rail on desktop, it is a drawer on mobile, and its groups open and close.
 * Every one of those is a layout change, and Filament performs all of them in a
 * single frame -- labels vanish, the rail snaps to its new width, and the whole
 * content area jumps left. This module is what turns those three jump cuts into
 * movements.
 *
 * Four things decide the shape of the file.
 *
 * 1. ALPINE OWNS THE STATE, NOT US. `$store.sidebar` toggles classes:
 *    `fi-sidebar-open` on `#fi-main-sidebar`, `fi-collapsed` on a
 *    `.fi-sidebar-group`, `display: none` on every label. Nothing here reaches
 *    into that store or tries to delay it. MutationObservers watch those class
 *    names and animate around a change that has already happened, which means
 *    the panel still works exactly as Filament built it if this module never
 *    runs, throws, or is reverted mid-flight.
 *
 * 2. THE ONE THING THAT CANNOT BE DONE AFTER THE FACT IS AN EXIT. A label that
 *    is already `display: none` cannot be faded out, and un-hiding it would
 *    fight `x-show` and could leave text stranded in a collapsed rail. So the
 *    label exit starts on `pointerdown`, in the gap before the click lands --
 *    and carries a failsafe that puts every label back if the click never
 *    arrives (see `restoreNavText`).
 *
 * 3. WIDTH IS NOT ANIMATABLE HERE. The collapsed sidebar has no width of its
 *    own -- it is as wide as its icons, and `.fi-main-ctn` is the flex sibling
 *    that absorbs the difference. Tweening either one's width would be a layout
 *    pass per frame on the tallest element on the page. Flip does it properly:
 *    the layout change lands in one frame, and the visual difference is paid
 *    off on the compositor with transforms.
 *
 * 4. TRANSFORMS ARE TRANSIENT. Filament renders action modals (`position:
 *    fixed`) inline, so any element left holding a transform becomes the
 *    containing block for the modals inside it. Everything this module puts on
 *    `.fi-main-ctn` or on the drawer is cleared by `clearProps` the moment the
 *    tween lands, and the content-area slide is skipped outright while a modal
 *    is open.
 *
 * Not owned here: the travelling active-item marker. That belongs to
 * interactions.js, which FLIPs it between items on click and re-places it from
 * a ResizeObserver on `.fi-sidebar-nav` -- including when this module's collapse
 * changes the nav's width. Nothing below touches it or the elements it measures.
 *
 * Every `fi-*` selector below was read out of
 * vendor/filament/filament/resources/views/ -- livewire/{sidebar,topbar} and
 * components/{layout/index, sidebar/item, sidebar/group,
 * sidebar/database-notifications-trigger, tenant-menu, user-menu}.blade.php,
 * and `.fi-modal-open` from support's components/modal/index.blade.php. An
 * invented class name compiles to nothing and fails silently, which this
 * project has been bitten by before. Note `.fi-sidebar-item-btn`, not
 * `-button`.
 */

import { Flip } from 'gsap/Flip'

const STYLE_ID = 'vf-nav'

/* The sidebar's own id, set in livewire/sidebar.blade.php. Used rather than
   `.fi-sidebar` because a panel can render more than one sidebar-shaped thing
   and only this one carries the open state. */
const SIDEBAR_ID = 'fi-main-sidebar'
const OPEN_CLASS = 'fi-sidebar-open'
const GROUP_COLLAPSED_CLASS = 'fi-collapsed'

/* Tailwind's `lg`, which is where Filament switches the sidebar from a drawer
   to part of the page. Written as rem because that is what Filament's own
   `lg:` variants compile to. */
const DESKTOP = '(min-width: 64rem)'
const MOBILE = '(max-width: 63.999rem)'

/* Every control that toggles the sidebar. All of them are `x-on:click`
   handlers on `$store.sidebar`, so a capture-phase listener on `document`
   reaches them before Alpine does -- which is the only place a "before" state
   can be measured. */
const TOGGLES = [
    '.fi-topbar-open-sidebar-btn',
    '.fi-topbar-close-sidebar-btn',
    '.fi-topbar-open-collapse-sidebar-btn',
    '.fi-topbar-close-collapse-sidebar-btn',
    '.fi-sidebar-open-collapse-sidebar-btn',
    '.fi-sidebar-close-collapse-sidebar-btn',
    '.fi-layout-sidebar-toggle-btn',
    '.fi-sidebar-close-overlay',
].join(', ')

/* Everything Alpine hides when the sidebar collapses to a rail. `x-show` sets
   `display: none` outright with no leave transition, which is the jump cut this
   list exists to soften. */
const LABELS = [
    '.fi-sidebar-item-label',
    '.fi-sidebar-item-badge-ctn',
    '.fi-sidebar-group-btn',
    '.fi-sidebar-header-logo-ctn',
    '.fi-sidebar-database-notifications-btn-label',
    '.fi-sidebar-database-notifications-btn-badge-ctn',
    '.fi-tenant-menu-trigger-text',
    '.fi-user-menu-trigger-text',
].join(', ')

/* What actually moves when the rail forms: the icons re-centre inside their
   buttons as the labels leave, and the little connector dot of a nested item
   goes with them. Small, transform-only, and holding no controls of their own
   -- exactly what Flip is for. */
const ICONS = [
    '.fi-sidebar-item-btn > .fi-icon',
    '.fi-sidebar-item-grouped-border',
    '.fi-sidebar-group-btn > .fi-icon',
].join(', ')

/* Rows for the mobile drawer's cascade and for a group opening. The `<li>`
   wrappers, never the `<a>` inside them: motion.css drives the anchor's hover
   lift with `translate:` and there is no reason to put a second transform on
   the same element. */
const ROWS = '.fi-sidebar-group-btn, .fi-sidebar-group-items > .fi-sidebar-item'

/* Ceilings. A sidebar with more entries than this is a directory, not a
   composition, and staggering all of it would just be latency. */
const MAX_ROWS = 40
const MAX_ICONS = 60

/* How long a measurement taken at click time stays usable. Alpine flushes its
   reactive effects in a microtask, so in practice the class lands within a
   frame; anything older than this is a click that did not toggle anything. */
const STATE_MAX_AGE = 800

const CSS = `
/* Registered so GSAP can tween it: an unregistered custom property reads back
   as an empty string and the tween has nothing to interpolate from. */
@property --vf-nav-edge { syntax: '<number>'; inherits: false; initial-value: 0; }

/* Filament's own primary scale, so nothing here introduces a colour. */
:root { --vf-nav-accent: var(--primary-500, #f59e0b); }
.dark { --vf-nav-accent: var(--primary-400, #fbbf24); }

/* --- Group chevron ---------------------------------------------------------
   Filament turns the disclosure chevron with \`rotate: -180deg\` on
   \`.fi-sidebar-group.fi-collapsed\` (vendor/filament/filament/resources/css/
   components/sidebar.css:4). That rotation used to ride \`.fi-icon-btn\`'s
   blanket \`transition\` -- but motion.css and interactions.js each narrowed
   that property list for their own reasons, and \`rotate\` fell off both, so
   the chevron now snaps through 180 degrees in a single frame.

   Handed back here rather than driven from GSAP: a CSS transition on the
   \`rotate\` property composes with the \`transform\` GSAP writes elsewhere,
   survives a context revert, and cannot leave a collapsed group wearing a
   chevron that points the wrong way. Specificity is (0,3,0) so it outranks
   interactions.js's \`.fi-icon-btn[data-vf-pointer]\` (0,2,0) whichever
   stylesheet the browser happens to see last. */
.fi-sidebar-group > .fi-sidebar-group-btn > .fi-sidebar-group-collapse-btn {
    transition-property: rotate, translate, scale, background-color, color, opacity;
    transition-duration: 420ms;
    transition-timing-function: var(--vf-spring, cubic-bezier(0.34, 1.36, 0.5, 1));
}

/* --- Label re-entry --------------------------------------------------------
   When the rail expands, Alpine fades the labels back in through
   \`.fi-transition-enter\`, whose Tailwind \`transition\` shorthand covers
   \`transform\` as well as \`opacity\`. This module slides those same labels in
   with GSAP, and a live transition on transform would re-ease every frame the
   tween writes -- the slide would land late with its easing smeared flat.

   So the two layers split the work: opacity stays CSS's (Alpine reads the
   computed duration back to decide when the transition has finished, so it has
   to keep a real one), transform becomes GSAP's. */
@media ${DESKTOP} {
    .fi-sidebar-item-label.fi-transition-enter,
    .fi-sidebar-item-badge-ctn.fi-transition-enter,
    .fi-sidebar-group-btn.fi-transition-enter,
    .fi-sidebar-group-items.fi-transition-enter,
    .fi-sidebar-database-notifications-btn-label.fi-transition-enter,
    .fi-sidebar-database-notifications-btn-badge-ctn.fi-transition-enter {
        transition-property: opacity;
        transition-duration: 260ms;
        transition-timing-function: var(--vf-ease, cubic-bezier(0.16, 1, 0.3, 1));
        transition-delay: 80ms;
    }
}

/* --- Mobile drawer ---------------------------------------------------------
   Filament slides the drawer with Tailwind's \`transition-all\`: 150ms on the
   default ease, which on a surface this size reads as a panel being yanked
   rather than opened. Only the timing is replaced -- the translate itself stays
   Filament's, so nothing here can leave the drawer parked half-open.

   \`transform\` is deliberately dropped from the property list. GSAP takes it
   over for the depth flourish below, and Filament moves the drawer with the
   individual \`translate\` property (Tailwind 4 compiles \`-translate-x-full\`
   that way), so the two compose instead of fighting. */
@media ${MOBILE} {
    .fi-sidebar.fi-main-sidebar {
        transition-property: translate, width, box-shadow, background-color, opacity;
        transition-duration: 420ms;
        transition-timing-function: var(--vf-ease, cubic-bezier(0.16, 1, 0.3, 1));
    }

    /* The backdrop. Alpine fades its opacity with an inline transition
       (\`x-transition.opacity.300ms\` in components/layout/index.blade.php), so a
       blur declared here rides that fade in and back out for free -- no second
       animation, and the element is \`display: none\` while the drawer is shut,
       so it costs nothing at rest. Modest on purpose: a backdrop filter is the
       most expensive thing in this whole motion layer on a 2-core box. */
    .fi-sidebar-close-overlay {
        -webkit-backdrop-filter: blur(4px) saturate(115%);
        backdrop-filter: blur(4px) saturate(115%);
    }
}

/* --- Collapsed rail edge ---------------------------------------------------
   The hover affordance: a hairline of the panel's accent down the rail's
   trailing edge, saying "there is more this way". Driven from JS through
   --vf-nav-edge, and drawn as a pseudo-element rather than an appended node
   because the sidebar is a Livewire component and its morph deletes DOM it did
   not render. Absolutely positioned, so it is not a flex item of the sidebar's
   own column. */
.fi-sidebar.fi-main-sidebar::after {
    content: '';
    position: absolute;
    inset-block: 0;
    inset-inline-end: 0;
    width: 2px;
    pointer-events: none;
    opacity: var(--vf-nav-edge, 0);
    background-image: linear-gradient(
        to bottom,
        transparent,
        var(--vf-nav-accent),
        transparent
    );
}
`

/**
 * The scope built by the previous run. Module scope, because the runtime
 * re-invokes this function on every `livewire:navigated` but never re-imports
 * the module -- so this is the only thing that can reach the listeners, the
 * observers and the stylesheet the last run left behind.
 */
let activeScope = null

/**
 * Sidebar collapse/expand, nav groups, the mobile drawer and the collapsed-rail
 * hover affordance.
 *
 * @param {{gsap: import('gsap').gsap, ScrollTrigger: object, MOTION: object}} api
 * @returns {null}
 */
export function navigation({ gsap, MOTION }) {
    if (typeof document === 'undefined' || !document.body) {
        return null
    }

    gsap.registerPlugin(Flip)

    // Undo the previous run before anything else. The runtime's context revert
    // normally gets here first; this is what covers a run that was interrupted
    // before the revert could reach it.
    if (activeScope) {
        activeScope.dispose()
        activeScope = null
    }

    const sidebar = document.getElementById(SIDEBAR_ID)
    const nav = sidebar ? sidebar.querySelector('.fi-sidebar-nav') : null

    // A panel can be configured with top navigation or with none at all, and
    // the auth screens have no chrome whatsoever.
    if (!sidebar || !nav) {
        return null
    }

    const scope = createScope()
    activeScope = scope

    // gsap.context() reverts tweens; it does not remove listeners, observers or
    // an injected stylesheet. This nested context exists only so the runtime's
    // revert reaches the disposer below.
    gsap.context(() => () => {
        if (activeScope === scope) {
            activeScope = null
        }

        scope.dispose()
    })

    injectStylesheet(scope)

    /*
     * `gsap.context()` with no argument hands back the context we are currently
     * running inside -- the runtime's. Tweens created later, from a pointer
     * handler or an observer, would otherwise be born with no context at all:
     * nothing would revert them, and the inline transforms they left behind
     * would survive the next navigation frozen mid-animation.
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

    const rtl = getComputedStyle(sidebar).direction === 'rtl'
    const lead = rtl ? -1 : 1

    const isDesktop = () => window.matchMedia(DESKTOP).matches
    const isOpen = () => sidebar.classList.contains(OPEN_CLASS)

    /* Whether collapsing to a rail is even possible. Filament only hides the
       labels when the panel opted into a desktop-collapsible sidebar; without
       it the whole label choreography below would be animating text that never
       goes anywhere. Both body classes come from components/layout/index.blade.php. */
    const collapsible =
        document.body.classList.contains('fi-body-has-sidebar-collapsible-on-desktop') ||
        document.body.classList.contains('fi-body-has-sidebar-fully-collapsible-on-desktop')

    const take = (selector, root, limit) => {
        const found = (root || nav).querySelectorAll(selector)

        return found.length ? Array.prototype.slice.call(found, 0, limit) : []
    }

    /* Anything Alpine has already hidden has a zero box. Filtering it out is
       not cosmetic: a hidden element still accepts a tween, and tweening a
       dozen of them every time the rail changes is pure waste. */
    const onScreen = (elements) => elements.filter((el) => el.getBoundingClientRect().width > 0)

    /* Inline styling this module may have written, removed without going
       through GSAP. Used on teardown, where the runtime is mid-revert and
       creating even a zero-duration tween would hand the context something new
       to track just as it stops tracking. */
    const stripInline = (elements) => {
        elements.forEach((el) => {
            el.style.removeProperty('opacity')
            el.style.removeProperty('transform')
            el.style.removeProperty('will-change')
        })
    }

    /* ------------------------------------------------------------------ *
     * Readability failsafe
     *
     * Rule one of this panel's motion layer: an operator must never be unable
     * to read something because a tween did not fire. Every path in this file
     * that lowers opacity is paired with a call to this, either on completion
     * or on a wall-clock timer -- the label exit in particular runs on a
     * `pointerdown` whose click may never arrive.
     *
     * clearProps rather than `opacity: 1`, so the element ends up with no
     * inline styling of ours at all and Alpine's own `x-show` and
     * `.fi-transition-enter` keep working afterwards.
     * ------------------------------------------------------------------ */

    const restoreNavText = (animate, only) => {
        const nodes = (only || take(`${LABELS}, ${ROWS}`, sidebar, MAX_ROWS * 2)).filter(
            (node) => node.isConnected,
        )

        if (!nodes.length) {
            return
        }

        const RESIDUE = 'opacity,transform,willChange'

        if (!animate) {
            // The kill is what makes this a restore rather than a suggestion. A
            // still-running exit rewrites `opacity: 0` on every frame, so
            // clearing the inline style out from under it would be undone by
            // the next tick and undone again when it lands.
            gsap.killTweensOf(nodes)
            gsap.set(nodes, { clearProps: RESIDUE })

            return
        }

        owned(() =>
            gsap.to(nodes, {
                opacity: 1,
                x: 0,
                y: 0,
                duration: MOTION.fast,
                ease: MOTION.easeSoft,
                overwrite: true,
                clearProps: RESIDUE,
            }),
        )
    }

    /*
     * The teardown half of the same guarantee, and deliberately the ONLY sweep
     * that runs at module scope.
     *
     * There is no matching sweep on the way in. entrance.js cascades
     * `.fi-sidebar-item` and the brand on a cold load and leaves its own inline
     * transform and opacity on them while it does; a tidy-up here would land in
     * the middle of that and clobber a frame of it. It is also unnecessary --
     * every tween below is created through `owned()`, so it belongs to the
     * runtime's context, and the revert that precedes each boot has already put
     * anything an interrupted exit touched back where it was. This disposer runs
     * inside that revert, before entrance is rebuilt, so it cannot collide.
     */
    scope.add(() => stripInline(take(`${LABELS}, ${ROWS}`, sidebar, MAX_ROWS * 2)))

    /* ------------------------------------------------------------------ *
     * Desktop collapse / expand
     *
     * Three things happen at once, and they are three separate mechanisms
     * because they are three different kinds of change:
     *
     *   - the labels LEAVE, which has to start before Alpine hides them;
     *   - the icons MOVE, which is a layout change and therefore Flip's job;
     *   - the content area REFLOWS, which is one big translate paid off on the
     *     compositor and cleared the instant it lands.
     * ------------------------------------------------------------------ */

    /* The "before" measurement, taken in a capture-phase click handler so it is
       read while the sidebar is still in its old state. */
    let pending = null

    const mainContainer = () => document.querySelector('.fi-main-ctn')

    const captureState = () => {
        if (!isDesktop() || !collapsible) {
            return
        }

        const icons = onScreen(take(ICONS, nav, MAX_ICONS))

        if (!icons.length) {
            return
        }

        const main = mainContainer()

        pending = {
            at: performance.now(),
            open: isOpen(),
            icons: Flip.getState(icons),
            targets: icons,
            // A single read of the content area's leading edge. Its width
            // changes too, but only the edge that moves is worth animating --
            // `.fi-layout` is `overflow-x: clip`, so the far edge overhanging
            // for a few hundred milliseconds is clipped rather than scrolled.
            main,
            mainLeft: main ? main.getBoundingClientRect().left : null,
        }
    }

    /**
     * The labels retreating toward the rail, started on `pointerdown` -- the
     * only window in which they are still on screen. From `end` so the list
     * zips upward toward the header rather than peeling away from it.
     */
    const exitLabels = () => {
        if (!isDesktop() || !collapsible || !isOpen()) {
            return
        }

        const labels = onScreen(take(LABELS, sidebar, MAX_ROWS))

        if (!labels.length) {
            return
        }

        owned(() =>
            gsap.to(labels, {
                opacity: 0,
                x: -10 * lead,
                duration: 0.18,
                ease: 'power2.in',
                overwrite: 'auto',
                stagger: { amount: 0.09, from: 'end' },
            }),
        )

        // The click may never come: a press that drags off the button, a
        // context menu, a tab away mid-press. Nothing else would ever put this
        // text back.
        owned(() =>
            gsap.delayedCall(0.7, () => {
                if (isOpen()) {
                    restoreNavText(true)
                }
            }),
        )
    }

    /**
     * The labels arriving. GSAP owns the slide only -- the fade belongs to
     * Alpine's `.fi-transition-enter`, which the injected stylesheet narrows to
     * `opacity` precisely so these two can share the element.
     */
    const enterLabels = () => {
        // Whatever the exit left behind goes first. A label that begins its
        // slide still carrying an inline `opacity: 0` is a label nobody can read
        // until the slide finishes -- which is most of a second away.
        restoreNavText(false)

        const labels = take(LABELS, sidebar, MAX_ROWS)

        if (!labels.length) {
            return
        }

        owned(() =>
            gsap.fromTo(
                labels,
                { x: -14 * lead, willChange: 'transform' },
                {
                    x: 0,
                    duration: MOTION.base,
                    ease: MOTION.ease,
                    stagger: { amount: 0.22, from: 'start' },
                    overwrite: 'auto',
                    // Opacity is deliberately absent from both ends: it belongs
                    // to Alpine's `.fi-transition-enter` for the length of this
                    // slide, and was cleared above.
                    clearProps: 'transform,willChange',
                },
            ),
        )

        owned(() => gsap.delayedCall(1.1, () => restoreNavText(false)))
    }

    /**
     * The content area catching up with the rail.
     *
     * One translate on one element, cleared on completion. `.fi-main-ctn` holds
     * the page's action modals, and an element with a live transform becomes
     * the containing block for its `position: fixed` descendants -- so this is
     * skipped outright while a modal is open, and never outlives its own tween.
     */
    const reflowContent = (from) => {
        if (!from.main || from.mainLeft === null || !from.main.isConnected) {
            return
        }

        if (document.querySelector('.fi-modal-open')) {
            return
        }

        const delta = from.mainLeft - from.main.getBoundingClientRect().left

        // Sub-pixel deltas are the sidebar not having changed width at all.
        if (Math.abs(delta) < 1) {
            return
        }

        owned(() =>
            gsap.fromTo(
                from.main,
                { x: delta, willChange: 'transform' },
                {
                    x: 0,
                    duration: MOTION.base * 0.8,
                    ease: MOTION.ease,
                    overwrite: 'auto',
                    clearProps: 'transform,willChange',
                },
            ),
        )
    }

    /**
     * The icons finding their new places. Flip records where they were, the
     * browser has already put them where they go, and the difference is paid
     * off entirely in transforms -- no per-frame layout on what is often the
     * tallest element on the page.
     */
    const reflowIcons = (from) => {
        const alive = from.targets.filter((icon) => icon.isConnected)

        if (!alive.length) {
            return
        }

        owned(() =>
            Flip.from(from.icons, {
                duration: MOTION.base * 0.8,
                ease: MOTION.ease,
                stagger: { amount: 0.12, from: 'start' },
                // scale: true is not cosmetic here. Without it Flip pays off a
                // size difference by tweening width and height, which is a
                // layout pass per frame -- and an icon Alpine hid during the
                // collapse measures 0x0, so it would produce exactly that.
                // On scaleX/scaleY the same case costs nothing and stays on the
                // compositor.
                scale: true,
                // Icons carry no controls, and the transform is gone the moment
                // the tween ends, so nothing here can anchor a modal.
                onComplete: () =>
                    gsap.set(alive, { clearProps: 'transform,transformOrigin,willChange' }),
            }),
        )
    }

    /** One pulse of the rail's trailing edge, so the eye follows the new shape. */
    const flashEdge = () => {
        owned(() =>
            gsap.fromTo(
                sidebar,
                { '--vf-nav-edge': 0 },
                {
                    '--vf-nav-edge': 0.9,
                    duration: MOTION.fast,
                    ease: MOTION.easeSoft,
                    overwrite: 'auto',
                    yoyo: true,
                    repeat: 1,
                    repeatDelay: 0.12,
                    onComplete: () => sidebar.style.removeProperty('--vf-nav-edge'),
                },
            ),
        )
    }

    const onSidebarToggled = (open) => {
        const from = pending
        pending = null

        if (!isDesktop()) {
            drawer(open)

            return
        }

        if (open) {
            enterLabels()
        } else {
            // Whatever the exit tween left behind is on elements Alpine has now
            // hidden, so clearing it is invisible -- and it has to happen, or
            // the next expand would fade text in from an inline opacity of 0.
            restoreNavText(false)
            flashEdge()
        }

        if (!from || performance.now() - from.at > STATE_MAX_AGE || from.open === open) {
            return
        }

        reflowIcons(from)
        reflowContent(from)
    }

    /* ------------------------------------------------------------------ *
     * Mobile drawer
     *
     * The slide and the backdrop are CSS (see the injected stylesheet -- the
     * timing is ours, the translate stays Filament's). What GSAP adds is the
     * part CSS cannot do: the rows cascading in behind the moving edge, and one
     * frame of perspective on the panel itself so it reads as a sheet swinging
     * in rather than a rectangle sliding.
     * ------------------------------------------------------------------ */

    const drawer = (open) => {
        if (!open) {
            // Exits are quicker and less ceremonious than entrances -- the
            // decision is already made. Just leave nothing behind.
            restoreNavText(false)

            return
        }

        const rows = take(ROWS, nav, MAX_ROWS)

        if (rows.length) {
            owned(() =>
                gsap.fromTo(
                    rows,
                    { x: -26 * lead, opacity: 0 },
                    {
                        x: 0,
                        opacity: 1,
                        duration: MOTION.base,
                        ease: MOTION.ease,
                        // A total amount, not a per-item rate: a panel with six
                        // entries and one with thirty finish inside the same
                        // window, so nothing is ever unreadable for long.
                        stagger: { amount: 0.24, from: 'start', ease: 'power2.in' },
                        delay: 0.06,
                        overwrite: 'auto',
                        clearProps: 'transform,opacity,willChange',
                    },
                ),
            )

            owned(() =>
                gsap.delayedCall(1.2, () => {
                    if (sidebar.classList.contains(OPEN_CLASS)) {
                        restoreNavText(true, rows)
                    }
                }),
            )
        }

        // Transient by construction: cleared the moment it lands, so the drawer
        // is never left holding a transform that could anchor a fixed child.
        owned(() =>
            gsap.fromTo(
                sidebar,
                { rotationY: -7 * lead, transformPerspective: 1400, transformOrigin: '0% 50%' },
                {
                    rotationY: 0,
                    duration: 0.52,
                    ease: MOTION.ease,
                    overwrite: 'auto',
                    clearProps: 'transform,transformOrigin,transformPerspective,willChange',
                },
            ),
        )
    }

    /* ------------------------------------------------------------------ *
     * Nav groups
     *
     * Alpine's `x-collapse.duration.200ms` already animates the height, and the
     * chevron is turned by the CSS above. Neither of them can stagger the items
     * inside, which is the whole difference between a box growing and a list
     * opening -- so that is all this does.
     * ------------------------------------------------------------------ */

    const groupToggled = (group, collapsed) => {
        const items = take(':scope > .fi-sidebar-group-items > .fi-sidebar-item', group, MAX_ROWS)

        if (!items.length) {
            return
        }

        if (collapsed) {
            // Quick, and from the bottom, so the list folds up into its header
            // inside the 200ms x-collapse spends closing the box.
            owned(() =>
                gsap.to(items, {
                    opacity: 0,
                    y: -4,
                    duration: 0.14,
                    ease: 'power2.in',
                    overwrite: 'auto',
                    stagger: { amount: 0.05, from: 'end' },
                    // The row is `display: none` by the time this lands, so
                    // clearing is invisible -- and mandatory, or re-opening the
                    // group would reveal rows stuck at zero opacity.
                    clearProps: 'opacity,transform',
                }),
            )

            // Re-opened inside the fold: the rows are on screen again and their
            // fade is the only reason they are not readable. Scoped to this
            // group's own rows so the failsafe cannot reach across the sidebar
            // and interrupt something else.
            owned(() =>
                gsap.delayedCall(0.6, () => {
                    if (!group.classList.contains(GROUP_COLLAPSED_CLASS)) {
                        restoreNavText(true, items)
                    }
                }),
            )

            return
        }

        owned(() =>
            gsap.fromTo(
                items,
                { opacity: 0, y: -8 },
                {
                    opacity: 1,
                    y: 0,
                    duration: MOTION.base * 0.7,
                    ease: MOTION.ease,
                    overwrite: 'auto',
                    stagger: { amount: 0.16, from: 'start' },
                    clearProps: 'opacity,transform,willChange',
                },
            ),
        )

        owned(() =>
            gsap.delayedCall(1, () => {
                if (!group.classList.contains(GROUP_COLLAPSED_CLASS)) {
                    restoreNavText(true, items)
                }
            }),
        )
    }

    /* ------------------------------------------------------------------ *
     * Wiring
     * ------------------------------------------------------------------ */

    // Capture phase, on `document`: it runs before Alpine's own bubble-phase
    // handler, which is the only moment a "before" state exists -- and it
    // survives Livewire replacing any of these buttons, which a listener bound
    // to the button itself would not.
    scope.on(
        document,
        'pointerdown',
        (event) => {
            if (event.button > 0) {
                return
            }

            const target = event.target

            if (!target || !target.closest || !target.closest(TOGGLES)) {
                return
            }

            exitLabels()
        },
        { capture: true, passive: true },
    )

    scope.on(
        document,
        'click',
        (event) => {
            const target = event.target

            if (!target || !target.closest || !target.closest(TOGGLES)) {
                return
            }

            try {
                captureState()
            } catch (error) {
                // A measurement that fails costs the flourish, never the panel.
                pending = null
            }
        },
        { capture: true },
    )

    /*
     * Two observers rather than one on a shared ancestor: `.fi-layout` would
     * mean every class change anywhere in the page -- and this panel morphs
     * tables every 30 seconds -- waking a callback that only cares about two
     * class names.
     */
    let wasOpen = isOpen()

    /* Seeded now so the first mutation we see is a real state change rather
       than whatever class Alpine happens to write on hydration. A group that
       arrives later through a morph is simply unknown, and its first genuine
       toggle animates -- which is the right default. */
    const collapsedGroups = new WeakMap()

    const seedGroups = () => {
        take('.fi-sidebar-group', nav, MAX_ROWS).forEach((group) => {
            if (!collapsedGroups.has(group)) {
                collapsedGroups.set(group, group.classList.contains(GROUP_COLLAPSED_CLASS))
            }
        })
    }

    seedGroups()

    if (typeof MutationObserver === 'function') {
        const sidebarObserver = new MutationObserver(() => {
            const open = sidebar.classList.contains(OPEN_CLASS)

            if (open === wasOpen) {
                return
            }

            wasOpen = open

            try {
                onSidebarToggled(open)
            } catch (error) {
                console.warn('[vpn-forge motion] nav toggle failed', error)
                restoreNavText(true)
            }
        })

        sidebarObserver.observe(sidebar, { attributes: true, attributeFilter: ['class'] })
        scope.add(() => sidebarObserver.disconnect())

        // Only worth watching if a group can actually collapse. Without a label
        // Filament renders no disclosure button and no `x-collapse` at all, and
        // this panel's navigation is currently ungrouped.
        if (nav.querySelector('.fi-sidebar-group.fi-collapsible > .fi-sidebar-group-btn')) {
            const groupObserver = new MutationObserver((records) => {
                for (let i = 0; i < records.length; i += 1) {
                    const group = records[i].target

                    if (!group.classList || !group.classList.contains('fi-sidebar-group')) {
                        continue
                    }

                    const collapsed = group.classList.contains(GROUP_COLLAPSED_CLASS)

                    if (collapsedGroups.get(group) === collapsed) {
                        continue
                    }

                    collapsedGroups.set(group, collapsed)

                    try {
                        groupToggled(group, collapsed)
                    } catch (error) {
                        console.warn('[vpn-forge motion] nav group failed', error)
                        restoreNavText(true)
                    }
                }
            })

            groupObserver.observe(nav, {
                attributes: true,
                attributeFilter: ['class'],
                subtree: true,
            })

            scope.add(() => groupObserver.disconnect())
        }
    }

    // Livewire re-renders the sidebar for badge polling, which can bring in
    // groups that did not exist at boot. Only unknown ones are seeded:
    // overwriting a group we are already tracking would race the observer's own
    // microtask and swallow the toggle it is about to report.
    scope.on(document, 'livewire:update', seedGroups)

    /* ------------------------------------------------------------------ *
     * Hover affordance on the collapsed rail
     *
     * Filament already shows each item's label in a tooltip while the sidebar
     * is a rail. What it does not do is say that the rail itself opens. So the
     * whole rail leans a couple of pixels toward the content and lights its
     * trailing edge under the pointer -- the same gesture the expand button
     * performs, previewed.
     *
     * Behind `pointer: fine`: on a touch device there is no hover state, and
     * this would be listeners riding along on a phone for nothing.
     * ------------------------------------------------------------------ */

    if (collapsible) {
        const mm = gsap.matchMedia()
        scope.add(() => mm.revert())

        mm.add(`${DESKTOP} and (pointer: fine)`, () => {
            const hover = createScope()

            const lean = (amount) => {
                const icons = onScreen(take(ICONS, nav, MAX_ICONS))

                if (!icons.length) {
                    return
                }

                const vars = {
                    x: amount * lead,
                    duration: MOTION.base,
                    ease: MOTION.easeSoft,
                    overwrite: 'auto',
                    stagger: { amount: 0.14, from: 'start' },
                }

                // Only the settle back leaves nothing behind; clearing on the
                // way out would drop the lean on the frame it arrived.
                if (amount === 0) {
                    vars.clearProps = 'transform,willChange'
                }

                owned(() => gsap.to(icons, vars))
            }

            hover.on(
                sidebar,
                'pointerenter',
                () => {
                    // Mid-collapse the icons belong to Flip; a second tween on
                    // the same transform would fight it. `pending` is checked by
                    // age as well as existence -- a click that toggled nothing
                    // leaves one behind, and it must not disable the hover for
                    // the rest of the page's life.
                    if (isOpen() || (pending && performance.now() - pending.at < STATE_MAX_AGE)) {
                        return
                    }

                    owned(() =>
                        gsap.to(sidebar, {
                            '--vf-nav-edge': 1,
                            duration: MOTION.fast,
                            ease: MOTION.easeSoft,
                            overwrite: 'auto',
                        }),
                    )

                    lean(3)
                },
                { passive: true },
            )

            hover.on(
                sidebar,
                'pointerleave',
                () => {
                    owned(() =>
                        gsap.to(sidebar, {
                            '--vf-nav-edge': 0,
                            duration: MOTION.base,
                            ease: MOTION.easeSoft,
                            overwrite: 'auto',
                            onComplete: () => sidebar.style.removeProperty('--vf-nav-edge'),
                        }),
                    )

                    lean(0)
                },
                { passive: true },
            )

            return () => {
                hover.dispose()
                sidebar.style.removeProperty('--vf-nav-edge')
            }
        })
    }

    scope.add(() => {
        pending = null
        sidebar.style.removeProperty('--vf-nav-edge')
    })

    return null
}

/* ------------------------------------------------------------------------- */
/* Lifecycle helpers                                                          */
/* ------------------------------------------------------------------------- */

/**
 * A bag of teardown callbacks. Every listener, observer, timer and DOM
 * insertion in this file goes through one of these, because gsap.context()
 * reverts tweens and nothing else.
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
                    console.warn('[vpn-forge motion] nav cleanup failed', error)
                }
            }
        },
    }
}

function injectStylesheet(scope) {
    const existing = document.getElementById(STYLE_ID)

    // Removed rather than reused: a run that was interrupted before its
    // disposer fired would otherwise leave a second copy in the head.
    if (existing) {
        existing.remove()
    }

    const style = document.createElement('style')
    style.id = STYLE_ID
    style.textContent = CSS
    document.head.appendChild(style)

    scope.add(() => style.remove())
}
