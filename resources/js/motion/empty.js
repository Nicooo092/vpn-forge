/**
 * Empty states.
 * -------------
 * In most panels an empty table is a failure. In this one it is usually the
 * correct answer: no IP restrictions configured means the panel is reachable
 * from anywhere, no blocklists means none were subscribed to, no connection
 * logs on a fresh install means nobody has connected yet. Those screens are
 * read as often as any table, and left unanimated they look like something
 * failed to load.
 *
 * So this module treats an empty state as a composition rather than a gap: the
 * icon arrives on a spring inside a soft halo, the heading and description
 * cascade under it, the call to action settles last, and then the icon breathes
 * very slowly so a still screen reads as idle rather than broken.
 *
 * Two states, two different pieces of choreography. Filament renders the same
 * markup whether a table has never had rows or whether a search just matched
 * nothing, so the difference is read off the live DOM instead: a non-empty
 * search field or a rendered filter-indicator bar inside the owning
 * `.fi-ta-ctn` means the operator is looking for something.
 *
 *   - RESTING (never had data). The full arrival, ~0.85s, and the ambient idle
 *     afterwards. This screen is where you will sit for a while.
 *   - FILTERED (nothing matched). Half the duration, no overshoot, no idle, and
 *     one pass of light across the block instead -- the panel showing you it
 *     looked. You typed a query and you want the answer now, and an icon
 *     bobbing gently under a result you are about to replace is noise.
 *
 * Constraints this file holds itself to:
 *
 *   - NOTHING IS LEFT UNREADABLE. An empty state already on screen is animated
 *     immediately, so it is never hidden and waiting. One below the fold is
 *     prepared and gated on a ScrollTrigger, and two timed sweeps force
 *     anything hidden-but-visible back to full opacity if that trigger never
 *     fires.
 *   - NO PERMANENT TRANSFORM ON ANYTHING HOLDING A CONTROL. Filament renders
 *     action modals (`position: fixed`) inline, and a live transform on an
 *     ancestor would anchor them to that ancestor instead of the viewport. The
 *     only lasting transform here is the idle float on the icon pill, which
 *     contains one `<svg>` and nothing else. Everything the call-to-action
 *     touches is transient and stripped by `clearProps` when it lands.
 *   - NOTHING IS INSERTED INTO MARKUP LIVEWIRE OWNS. The halo, the emitted ring
 *     and the scanning band are pseudo-elements in a stylesheet this module
 *     injects, driven through registered custom properties -- appended nodes
 *     would be eaten by the next morph. This mirrors interactions.js.
 *   - RE-ENTRANT. A table polls every 30-60s and re-renders its empty state
 *     with it. The arrival is replayed only when the state has actually CHANGED
 *     (its mode, heading or description), so a poll that finds the same nothing
 *     does not flash. The idle, whose tweens die with the replaced node, is
 *     re-established every time.
 *
 * @param {{gsap: import('gsap').gsap, ScrollTrigger: object, MOTION: object}} api
 */

import { CustomEase } from 'gsap/CustomEase'

const STYLE_ID = 'vf-empty-states'

/* Attributes this module writes onto Livewire-owned markup. They are what the
   injected stylesheet hangs off, and what the next run sweeps away. */
const MODE_ATTR = 'data-vf-empty-mode'
const ICON_ATTR = 'data-vf-empty-icon'
const SCAN_ATTR = 'data-vf-empty-scan'

const OWNED_SELECTOR = `[${MODE_ATTR}], [${ICON_ATTR}], [${SCAN_ATTR}]`

/* Custom properties written directly with setProperty rather than through a
   tween's clearProps, so they are listed here to be removed by hand. */
const OWNED_PROPS = ['--vf-empty-halo', '--vf-empty-ping', '--vf-empty-scan']

/* A page with more empty states than this is a page of placeholders, and the
   idle animations would start competing with each other for attention. */
const MAX_STATES = 4

/*
 * The two shapes Filament emits. `.fi-ta-empty-state` is the table's own,
 * verified in filament/tables/resources/views/index.blade.php; `.fi-empty-state`
 * is the shared support component (filament/support/.../empty-state.blade.php)
 * that widgets use. They are mutually exclusive -- a table that is handed a
 * custom empty state does not render its own wrapper -- so nothing is claimed
 * twice.
 */
const SHAPES = [
    {
        root: '.fi-ta-empty-state',
        content: '.fi-ta-empty-state-content',
        icon: '.fi-ta-empty-state-icon-bg',
        heading: '.fi-ta-empty-state-heading',
        description: '.fi-ta-empty-state-description',
        actions: '.fi-ta-actions',
    },
    {
        root: '.fi-empty-state',
        content: '.fi-empty-state-content',
        icon: '.fi-empty-state-icon-bg',
        heading: '.fi-empty-state-heading',
        description: '.fi-empty-state-description',
        actions: '.fi-empty-state-footer',
    },
]

const ROOT_SELECTOR = SHAPES.map((shape) => shape.root).join(', ')

/*
 * The panel's one bespoke ease, and the only reason CustomEase is imported. The
 * icon rises fast, overshoots to about 1.07 just past a third of the way, then
 * takes the remaining two thirds to settle -- a long tail that `back.out` does
 * not have, and which is what makes the arrival read as weight rather than as a
 * bounce.
 */
const BLOOM_PATH = 'M0,0 C0.13,0 0.2,1.14 0.42,1.07 C0.6,1.01 0.72,0.985 1,1'

/*
 * Every deferred callback in here -- rAF sweeps, ScrollTrigger gates, timed
 * failsafes -- belongs to the boot that created it. `livewire:navigated` and a
 * reduced-motion toggle both re-run this module, and a callback from the
 * previous run writing to an element the current run owns would fight it.
 */
let generation = 0

/*
 * Which empty states an operator has already been shown, keyed by the most
 * stable ancestor available. Module scope so it survives the runtime's
 * teardown/boot cycle, which is the whole point: this is what stops a 30s table
 * poll from replaying the arrival on a screen that has not changed. A full page
 * navigation replaces the key nodes, so a genuinely new page always animates.
 */
const shown = new WeakMap()

/* Past this many distinct signatures under one anchor the operator has been
   searching for a while and is not being told anything by a replay. */
const SIGNATURE_LIMIT = 12

const CSS = `
/* Registered so GSAP reads them back as numbers. An unregistered custom
   property computes to a string and cannot be interpolated. The names are
   prefixed --vf-empty- because motion.css already owns --vf-halo and
   --vf-sheen as colours. */
@property --vf-empty-halo { syntax: '<number>'; inherits: false; initial-value: 0; }
@property --vf-empty-ping { syntax: '<number>'; inherits: false; initial-value: 1; }
@property --vf-empty-scan { syntax: '<number>'; inherits: false; initial-value: 0; }

/* Tints only, no new palette: Filament's own primary and gray scales. */
:root {
    --vf-empty-glow: color-mix(in oklab, var(--primary-500, #f59e0b) 30%, transparent);
    --vf-empty-ring: color-mix(in oklab, var(--primary-500, #f59e0b) 45%, transparent);
    --vf-empty-band: color-mix(in oklab, var(--gray-950, #030712) 6%, transparent);
}

.dark {
    --vf-empty-glow: color-mix(in oklab, var(--primary-400, #fbbf24) 24%, transparent);
    --vf-empty-ring: color-mix(in oklab, var(--primary-400, #fbbf24) 36%, transparent);
    --vf-empty-band: rgb(255 255 255 / 0.07);
}

/* Nothing matched is not an accent-worthy outcome. The glow goes neutral so a
   fruitless search never wears the same colour as a screen that is simply
   waiting to be filled. Unregistered custom properties inherit, so redefining
   them on the root of the empty state re-tints its whole subtree. */
[${MODE_ATTR}='filtered'] {
    --vf-empty-glow: color-mix(in oklab, var(--gray-500, #6b7280) 20%, transparent);
    --vf-empty-ring: color-mix(in oklab, var(--gray-500, #6b7280) 28%, transparent);
}

/* --- The icon pill ---------------------------------------------------------
   Filament gives this a grey circular background and puts one icon in it. Both
   layers below are absolutely positioned, and positioned descendants paint
   after in-flow content -- so the icon itself is lifted above them rather than
   the layers being pushed behind, which would need a negative z-index and would
   then also sit behind the pill's own background. */

[${ICON_ATTR}] {
    position: relative;
}

[${ICON_ATTR}] > * {
    position: relative;
    z-index: 2;
}

/* The halo. Bleeds well past the pill's edge, so most of what you see is light
   around the icon rather than a wash over it. Only its opacity is driven. */
[${ICON_ATTR}]::after {
    content: '';
    position: absolute;
    inset: -55%;
    z-index: 0;
    border-radius: 50%;
    pointer-events: none;
    background-image: radial-gradient(
        circle at 50% 50%,
        var(--vf-empty-glow),
        transparent 62%
    );
    opacity: var(--vf-empty-halo);
}

/* One ring leaving the icon as it lands. Parked at --vf-empty-ping: 1, which is
   fully transparent, so it paints nothing at all until a tween drives it and
   nothing again once that tween is done. */
[${ICON_ATTR}]::before {
    content: '';
    position: absolute;
    inset: 0;
    z-index: 1;
    border-radius: 50%;
    pointer-events: none;
    border: 1px solid var(--vf-empty-ring);
    opacity: calc((1 - var(--vf-empty-ping)) * 0.55);
    transform: scale(calc(0.72 + var(--vf-empty-ping) * 1.15));
}

/* --- The scan ---------------------------------------------------------------
   One pass of light across the block, for the filtered state only. Same
   technique as the button sheen in interactions.js: the band lives inside a
   background image far wider than the box, and sweeping the background position
   carries it on from one edge and off the other. At --vf-empty-scan: 0 it is
   parked outside the box and paints nothing.

   position: relative here makes this the containing block for ABSOLUTE
   descendants only. Filament's action modals are position: fixed and are not
   affected. */
[${SCAN_ATTR}] {
    position: relative;
}

[${SCAN_ATTR}]::after {
    content: '';
    position: absolute;
    inset: -0.5rem;
    pointer-events: none;
    border-radius: 0.75rem;
    background-image: linear-gradient(
        100deg,
        transparent 42%,
        var(--vf-empty-band) 50%,
        transparent 58%
    );
    background-repeat: no-repeat;
    background-size: 240% 100%;
    background-position: calc(100% - var(--vf-empty-scan) * 100%) 0;
}
`

export function emptyStates({ gsap, ScrollTrigger, MOTION }) {
    if (typeof document === 'undefined' || !document.body) {
        return null
    }

    const boot = ++generation

    gsap.registerPlugin(CustomEase)

    /*
     * `gsap.context()` with no argument hands back the context we are running
     * inside -- the runtime's. Tweens created later, from a mutation callback or
     * a ScrollTrigger, would otherwise be born with no context at all: nothing
     * would revert them, and the inline styles they left behind would survive
     * the next navigation frozen mid-animation.
     */
    const host = gsap.context()

    /**
     * Runs `fn` as part of the runtime's context and hands back its result. The
     * result comes out through a closure rather than a return value because
     * `context.add()` treats a returned FUNCTION as a cleanup callback and would
     * invoke it on revert.
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

    // gsap.context() reverts tweens, not listeners, injected stylesheets or
    // attributes. This nested context exists only so the runtime's revert()
    // reaches the disposer below.
    gsap.context(() => () => scope.dispose())

    // Undo whatever a previous run left on markup that survived it, before
    // anything new is built.
    sweepOwnedAttributes()
    injectStylesheet(scope)

    const bloom = makeBloom(MOTION)
    const viewportHeight = () => window.innerHeight || document.documentElement.clientHeight || 0

    /* Live choreographies, one per empty state currently in the DOM. */
    const cells = new Set()

    scope.add(() => {
        cells.forEach((cell) => cell.dispose())
        cells.clear()
    })

    /* ------------------------------------------------------------------ *
     * Reading the state
     * ------------------------------------------------------------------ */

    /**
     * Is this empty state the result of a search or a filter, rather than the
     * table simply having no rows?
     *
     * Filament emits identical markup either way, so both signals are read off
     * the live DOM inside the table container that owns this empty state:
     * `.fi-ta-search-field` wraps the search input (and each per-column search
     * input), and `.fi-ta-filter-indicators` is only rendered when at least one
     * filter is actually applied.
     */
    function isFiltered(root) {
        const container = root.closest('.fi-ta-ctn')

        if (!container) {
            return false
        }

        if (container.querySelector('.fi-ta-filter-indicators')) {
            return true
        }

        const fields = container.querySelectorAll('.fi-ta-search-field input')

        for (let i = 0; i < fields.length; i += 1) {
            if (typeof fields[i].value === 'string' && fields[i].value.trim() !== '') {
                return true
            }
        }

        return false
    }

    /**
     * The most stable ancestor available, used to remember what has already been
     * shown. A Livewire component root is never replaced by its own morph, which
     * is exactly the property needed: the empty state inside it may be rebuilt
     * on every poll, but the key it is remembered under is not.
     */
    function anchorOf(root) {
        return (
            root.closest('[wire\\:id]') ||
            root.closest('.fi-ta-ctn') ||
            root.parentElement ||
            root
        )
    }

    /**
     * True the first time this exact state is seen under this anchor. A poll
     * that re-renders the same nothing returns false and the arrival is skipped;
     * a search that turns "No blocklists yet" into a filtered no-match returns
     * true, because the mode is part of the signature.
     */
    function isNewToTheOperator(root, signature) {
        const anchor = anchorOf(root)
        let seen = shown.get(anchor)

        if (!seen) {
            seen = new Set()
            shown.set(anchor, seen)
        }

        if (seen.has(signature)) {
            return false
        }

        if (seen.size >= SIGNATURE_LIMIT) {
            seen.clear()
        }

        seen.add(signature)

        return true
    }

    /* ------------------------------------------------------------------ *
     * Building one empty state
     * ------------------------------------------------------------------ */

    /**
     * Pull the parts of an empty state out of whichever of the two shapes it is.
     * Returns null when there is nothing worth animating.
     */
    function partsOf(root) {
        const shape = SHAPES.find((candidate) => root.matches(candidate.root))

        if (!shape) {
            return null
        }

        const icon = root.querySelector(shape.icon)
        const heading = root.querySelector(shape.heading)
        const description = root.querySelector(shape.description)
        const content = root.querySelector(shape.content)

        // Scoped to a direct child: `.fi-ta-actions` is also the class on the
        // table's own header toolbar, which is not ours and is several levels up.
        const actionsCtn = content ? content.querySelector(`:scope > ${shape.actions}`) : null

        // Prefer the buttons so they can arrive individually; fall back to the
        // container for an empty state whose footer holds something else.
        const buttons = actionsCtn
            ? Array.from(actionsCtn.querySelectorAll('.fi-btn, .fi-icon-btn'))
            : []
        const cta = buttons.length ? buttons : actionsCtn ? [actionsCtn] : []

        const text = [heading, description].filter(Boolean)
        const movers = [icon, ...text, ...cta].filter(Boolean)

        if (!movers.length) {
            return null
        }

        return { icon, heading, description, content, cta, text, movers }
    }

    /**
     * Insurance, not choreography. If the gate never fires -- a mis-measured
     * refresh, a table that grew under us, a Livewire update that moved the page
     * -- this is what guarantees an operator can read the screen. Only ever
     * raises opacity; never hides anything.
     */
    function forceReadable(parts) {
        const stuck = parts.movers.filter(
            (el) => el.isConnected && Number(gsap.getProperty(el, 'opacity')) < 0.99,
        )

        if (!stuck.length) {
            return
        }

        owned(() =>
            gsap.to(stuck, {
                opacity: 1,
                y: 0,
                scale: 1,
                rotate: 0,
                duration: MOTION.fast,
                ease: MOTION.easeSoft,
                overwrite: true,
                clearProps: 'opacity,visibility,transform,transformOrigin,willChange,transition,animation',
            }),
        )
    }

    /**
     * The arrival. Returns the timeline so the caller can hang the idle off its
     * completion.
     *
     * Both variants finish every opacity ramp well inside a second, and the
     * heading -- the one thing that says what the screen means -- is fully
     * opaque before the icon has finished settling.
     */
    function buildReveal(root, parts, mode) {
        const rich = mode === 'resting'

        const timeline = gsap.timeline({
            defaults: { ease: MOTION.ease, overwrite: 'auto' },
        })

        /* Nothing in Filament's theme or in motion.css transitions these today.
           Suppressed anyway, and handed straight back by clearProps: a CSS
           transition on a property GSAP is writing re-eases every frame and
           smears the tween flat, and eleven other modules are landing in this
           stylesheet. */
        timeline.set(parts.movers, { transition: 'none', animation: 'none' }, 0)

        if (parts.icon) {
            const from = rich
                ? { opacity: 0, scale: 0.42, rotate: -14 }
                : { opacity: 0, scale: 0.9, rotate: 0 }

            timeline
                .set(parts.icon, { willChange: 'transform', transformOrigin: '50% 50%' }, 0)
                .fromTo(
                    parts.icon,
                    from,
                    {
                        scale: 1,
                        rotate: 0,
                        duration: rich ? 0.9 : 0.34,
                        ease: rich ? bloom : MOTION.easeSoft,
                        // The pill ends up exactly as the server rendered it, so
                        // the idle float below starts from a clean y: 0.
                        clearProps: 'transform,transformOrigin,willChange,transition,animation',
                    },
                    0,
                )
                // Opacity is a separate, much shorter tween: the icon should be
                // legible long before it has finished settling, and an
                // overshooting ease would push opacity past 1 on the way.
                .to(
                    parts.icon,
                    {
                        opacity: 1,
                        duration: rich ? 0.34 : 0.22,
                        ease: MOTION.easeSoft,
                        clearProps: 'opacity',
                    },
                    0,
                )

            // The halo swells with the icon and stays lit at the value the idle
            // pulse starts from, so the handover between the two is invisible.
            timeline.fromTo(
                parts.icon,
                { '--vf-empty-halo': 0 },
                {
                    '--vf-empty-halo': rich ? 0.42 : 0.3,
                    duration: rich ? 0.75 : 0.4,
                    ease: 'power2.out',
                },
                0,
            )

            if (rich) {
                // One ring emitted as the icon lands. Runs past the end of
                // everything else on purpose -- it is the last thing to fade,
                // which is what makes the composition feel like it settled
                // rather than stopped.
                timeline.fromTo(
                    parts.icon,
                    { '--vf-empty-ping': 0 },
                    {
                        '--vf-empty-ping': 1,
                        duration: 1.15,
                        ease: 'power2.out',
                        onComplete: () => parts.icon.style.removeProperty('--vf-empty-ping'),
                    },
                    0.12,
                )
            } else {
                // Nothing to keep lit on a state you are about to replace.
                timeline.to(
                    parts.icon,
                    {
                        '--vf-empty-halo': 0,
                        duration: 0.5,
                        ease: 'power2.inOut',
                        onComplete: () => parts.icon.style.removeProperty('--vf-empty-halo'),
                    },
                    0.55,
                )
            }
        }

        if (parts.heading) {
            timeline.fromTo(
                parts.heading,
                { opacity: 0, y: rich ? 16 : 10 },
                {
                    opacity: 1,
                    y: 0,
                    duration: rich ? 0.55 : 0.34,
                    clearProps: 'opacity,transform,willChange,transition,animation',
                },
                rich ? 0.16 : 0.06,
            )
        }

        if (parts.description) {
            timeline.fromTo(
                parts.description,
                { opacity: 0, y: rich ? 14 : 8 },
                {
                    opacity: 1,
                    y: 0,
                    duration: rich ? 0.6 : 0.36,
                    clearProps: 'opacity,transform,willChange,transition,animation',
                },
                rich ? 0.24 : 0.11,
            )
        }

        if (parts.cta.length) {
            /* The call to action settles last, and it is the only thing here
               that gets a spring -- it is the one element on the screen you are
               meant to press. Every property it touches is stripped when the
               tween lands: these buttons open modals that Filament renders
               inline, and a transform left on an ancestor of a position: fixed
               node makes that node's containing block. */
            timeline.fromTo(
                parts.cta,
                { opacity: 0, y: rich ? 12 : 8, scale: rich ? 0.92 : 1 },
                {
                    opacity: 1,
                    y: 0,
                    scale: 1,
                    duration: rich ? 0.6 : 0.4,
                    ease: rich ? MOTION.spring : MOTION.easeSoft,
                    stagger: rich ? 0.06 : 0.03,
                    clearProps: 'opacity,transform,transformOrigin,willChange,transition,animation',
                },
                rich ? 0.36 : 0.16,
            )
        }

        if (!rich && parts.content) {
            /* The scan. Only on the filtered state, and only once: this is the
               panel saying it looked, not a loading indicator. */
            timeline.fromTo(
                parts.content,
                { '--vf-empty-scan': 0 },
                {
                    '--vf-empty-scan': 1,
                    duration: 0.85,
                    ease: 'power2.inOut',
                    onComplete: () => parts.content.style.removeProperty('--vf-empty-scan'),
                },
                0.05,
            )
        }

        return timeline
    }

    /**
     * The ambient idle: a very slow float paired with a slower halo pulse.
     *
     * The two periods are deliberately different and coprime-ish, so they drift
     * in and out of phase instead of ticking together -- which is the whole
     * difference between something breathing and something blinking. Both are
     * paused whenever the empty state is off screen, so a dashboard scrolled
     * past is not paying for animations nobody is looking at.
     */
    function buildIdle(root, icon, cell) {
        const float = owned(() =>
            gsap.to(icon, {
                y: -6,
                duration: 3.4,
                ease: 'sine.inOut',
                repeat: -1,
                yoyo: true,
            }),
        )

        const pulse = owned(() =>
            gsap.fromTo(
                icon,
                { '--vf-empty-halo': 0.42 },
                {
                    '--vf-empty-halo': 0.78,
                    duration: 4.3,
                    ease: 'sine.inOut',
                    repeat: -1,
                    yoyo: true,
                },
            ),
        )

        if (float) {
            cell.tweens.push(float)
        }

        if (pulse) {
            cell.tweens.push(pulse)
        }

        const gate = ScrollTrigger.create({
            trigger: root,
            start: 'top bottom',
            end: 'bottom top',
            onToggle: (self) => {
                const action = self.isActive ? 'play' : 'pause'

                if (float) {
                    float[action]()
                }

                if (pulse) {
                    pulse[action]()
                }
            },
        })

        cell.triggers.push(gate)

        if (!gate.isActive) {
            if (float) {
                float.pause()
            }

            if (pulse) {
                pulse.pause()
            }
        }
    }

    /**
     * Claim one empty state: mark it, decide which of the two states it is,
     * reveal it if the operator has not already been shown this exact screen,
     * and start the idle when the reveal lands.
     *
     * @returns {?object} the cell, or null if this node is not (yet) animatable.
     */
    function activate(root) {
        const parts = partsOf(root)

        if (!parts) {
            return null
        }

        // No box means a collapsed section, a closed modal, or markup Livewire
        // has not laid out yet. Deliberately not prepared: hiding something that
        // may never get a trigger is exactly the bug rule one exists to prevent.
        // A later sweep picks it up once it has a size.
        const box = root.getBoundingClientRect()

        if (!box.height) {
            return null
        }

        const mode = isFiltered(root) ? 'filtered' : 'resting'

        root.setAttribute(MODE_ATTR, mode)

        if (parts.icon) {
            parts.icon.setAttribute(ICON_ATTR, '')
        }

        if (mode === 'filtered' && parts.content) {
            parts.content.setAttribute(SCAN_ATTR, '')
        }

        const cell = {
            root,
            parts,
            idle: false,
            tweens: [],
            triggers: [],
            dispose() {
                this.tweens.forEach((tween) => tween.kill())
                this.triggers.forEach((trigger) => trigger.kill())
                this.tweens.length = 0
                this.triggers.length = 0

                const nodes = [this.parts.icon, this.parts.content].filter(
                    (node) => node && node.isConnected,
                )

                nodes.forEach((node) => {
                    OWNED_PROPS.forEach((name) => node.style.removeProperty(name))
                })

                const live = this.parts.movers.filter((node) => node.isConnected)

                if (live.length) {
                    // clearProps rather than a tween to opacity 1: this runs
                    // during the runtime's revert, and a tween created there
                    // would outlive the context that was supposed to own it.
                    // Stripping the inline styles hands every element straight
                    // back to the stylesheet, which is where it is readable.
                    gsap.set(live, {
                        clearProps:
                            'opacity,visibility,transform,transformOrigin,willChange,transition,animation',
                    })
                }

                if (this.root.isConnected) {
                    this.root.removeAttribute(MODE_ATTR)
                }

                if (this.parts.icon && this.parts.icon.isConnected) {
                    this.parts.icon.removeAttribute(ICON_ATTR)
                }

                if (this.parts.content && this.parts.content.isConnected) {
                    this.parts.content.removeAttribute(SCAN_ATTR)
                }
            },
        }

        const startIdle = () => {
            // Once per cell. Two idles on one icon would fight over the same y
            // and the pill would jitter instead of breathing.
            if (cell.idle || boot !== generation || !root.isConnected || !parts.icon) {
                return
            }

            cell.idle = true
            buildIdle(root, parts.icon, cell)
        }

        const heading = parts.heading ? parts.heading.textContent.trim() : ''
        const description = parts.description ? parts.description.textContent.trim() : ''
        const signature = `${mode}|${heading}|${description}`

        if (!isNewToTheOperator(root, signature)) {
            // Same nothing as last time -- a poll, not a change. No arrival, but
            // the idle still has to be rebuilt: its tweens died with the node
            // Livewire replaced.
            if (mode === 'resting') {
                startIdle()
            }

            return cell
        }

        let played = false

        const play = () => {
            if (played || boot !== generation || !root.isConnected) {
                return
            }

            played = true

            const timeline = owned(() => buildReveal(root, parts, mode))

            if (timeline) {
                cell.tweens.push(timeline)

                if (mode === 'resting') {
                    // Sequenced, never overlapped: the reveal's clearProps hands
                    // the icon back at y: 0, which is where the float starts.
                    timeline.eventCallback('onComplete', startIdle)
                }
            } else if (mode === 'resting') {
                startIdle()
            }
        }

        const height = viewportHeight()
        const onScreen = height > 0 && box.top < height * 0.95 && box.bottom > 0

        if (onScreen) {
            // Already being read. Animate now, so it is never hidden waiting for
            // an event.
            play()

            return cell
        }

        // Below (or above) the fold. Prepare it, gate it, and back the gate with
        // two sweeps that force it readable if the gate never fires.
        owned(() =>
            gsap.set(parts.movers, { opacity: 0, transition: 'none', animation: 'none' }),
        )

        const gate = ScrollTrigger.create({
            trigger: root,
            start: 'top 95%',
            end: 'bottom 5%',
            once: true,
            onEnter: play,
            // Scrolling back UP to an empty state that was above the viewport
            // never produces an onEnter, and without this it would sit at zero
            // opacity until a failsafe caught it.
            onEnterBack: play,
        })

        cell.triggers.push(gate)

        owned(() =>
            gsap.delayedCall(1.4, () => {
                if (boot === generation && root.isConnected && isOnScreen(root)) {
                    forceReadable(parts)
                }
            }),
        )

        // Late enough to catch an empty state Livewire deferred past the first.
        owned(() =>
            gsap.delayedCall(3.6, () => {
                if (boot === generation && root.isConnected && isOnScreen(root)) {
                    forceReadable(parts)
                }
            }),
        )

        return cell
    }

    function isOnScreen(node) {
        const height = viewportHeight()

        if (!height) {
            return false
        }

        const box = node.getBoundingClientRect()

        return box.height > 0 && box.bottom > 0 && box.top < height
    }

    /* ------------------------------------------------------------------ *
     * Sweeping
     *
     * The empty state an operator sees is rarely the one that was in the DOM at
     * boot: it appears when the last record is deleted, when a search matches
     * nothing, when a lazy widget finally resolves. So this runs on a coalesced
     * rAF whenever the page changes, and claims whatever is new.
     * ------------------------------------------------------------------ */

    function sweep() {
        if (boot !== generation) {
            return
        }

        // Prune first. Two reasons a cell stops being valid: Livewire replaced
        // the node, so its tweens will never draw anything again; or Livewire
        // MORPHED it in place and the state changed meaning underneath us --
        // a cleared search turns a filtered no-match back into the resting
        // screen, and that deserves the resting screen's arrival.
        cells.forEach((cell) => {
            const stale =
                !cell.root.isConnected ||
                cell.root.getAttribute(MODE_ATTR) !== (isFiltered(cell.root) ? 'filtered' : 'resting')

            if (stale) {
                cell.dispose()
                cells.delete(cell)
            }
        })

        const found = document.querySelectorAll(ROOT_SELECTOR)

        if (!found.length) {
            return
        }

        const live = new Set()
        cells.forEach((cell) => live.add(cell.root))

        for (let i = 0; i < found.length; i += 1) {
            if (cells.size >= MAX_STATES) {
                break
            }

            const root = found[i]

            if (live.has(root)) {
                continue
            }

            // A custom empty state could nest one shape inside the other. The
            // outermost one owns the composition.
            if (root.parentElement && root.parentElement.closest(ROOT_SELECTOR)) {
                continue
            }

            const cell = activate(root)

            if (cell) {
                cells.add(cell)
            }
        }
    }

    let frame = 0

    const schedule = () => {
        if (frame || boot !== generation) {
            return
        }

        frame = requestAnimationFrame(() => {
            frame = 0
            sweep()
        })
    }

    scope.add(() => {
        if (frame) {
            cancelAnimationFrame(frame)
        }

        frame = 0
    })

    sweep()

    /*
     * Three ways a new empty state can arrive, and all three are cheap. The
     * observer is the reliable one -- it sees a Livewire morph regardless of
     * which events that version dispatches -- and it only ever schedules a
     * frame, so a burst of mutations while an operator types in a search box
     * collapses into one sweep.
     */
    const observed = document.querySelector('.fi-main') || document.body

    if (typeof MutationObserver === 'function' && observed) {
        const observer = new MutationObserver(schedule)

        observer.observe(observed, { childList: true, subtree: true })
        scope.add(() => observer.disconnect())
    }

    scope.on(document, 'livewire:update', schedule)

    // A lazily loaded table can land after the observer is already watching a
    // subtree it has not been inserted into yet.
    owned(() => gsap.delayedCall(0.35, schedule))
    owned(() => gsap.delayedCall(1.2, schedule))
    owned(() => gsap.delayedCall(3, schedule))

    return null
}

/* ------------------------------------------------------------------------- */
/* Helpers                                                                    */
/* ------------------------------------------------------------------------- */

/**
 * A bag of teardown callbacks, matching the pattern in interactions.js. Every
 * listener and DOM insertion in this file goes through one so nothing survives a
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
        add(disposer) {
            disposers.push(disposer)
        },
        dispose() {
            while (disposers.length) {
                try {
                    disposers.pop()()
                } catch (error) {
                    // A failing disposer must not strand the ones behind it.
                    console.warn('[vpn-forge motion] empty-state cleanup failed', error)
                }
            }
        },
    }
}

/**
 * Strip everything a previous run wrote onto markup that outlived it. The
 * runtime reverts our tweens, but attributes and raw custom properties are ours
 * to clean up -- and a stale `data-vf-empty-icon` would leave a halo lit with no
 * tween behind it.
 */
function sweepOwnedAttributes() {
    document.querySelectorAll(OWNED_SELECTOR).forEach((node) => {
        node.removeAttribute(MODE_ATTR)
        node.removeAttribute(ICON_ATTR)
        node.removeAttribute(SCAN_ATTR)

        OWNED_PROPS.forEach((name) => node.style.removeProperty(name))
    })
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
    scope.add(sweepOwnedAttributes)
}

/**
 * The bloom ease, or the panel's stock spring if CustomEase refuses the path.
 * A malformed path throws, and one bad easing string must not cost the whole
 * module.
 */
function makeBloom(MOTION) {
    try {
        return CustomEase.create('vfEmptyBloom', BLOOM_PATH)
    } catch (error) {
        return MOTION.spring
    }
}
