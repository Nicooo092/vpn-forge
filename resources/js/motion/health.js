/**
 * Server health: arc gauges.
 * --------------------------
 * The Server health page is nine Filament stat cards, three of which are real
 * instrument readings -- CPU load, memory, disk. This module turns those three
 * into instruments: an SVG ring is injected into the card, the arc is flung out
 * to the reading with a needle head riding its tip, and it settles. When the
 * widget polls, the arc travels to the new reading instead of the page silently
 * swapping one number for another.
 *
 * Five things constrain every decision here.
 *
 * 1. THE DIGITS ARE NOT OURS. counters.js owns the text of every stat value and
 *    counts it up on first view. This module owns the arc and nothing else, and
 *    it reads the value through counters.js's own `data-vf-count-from` escape
 *    hatch, which holds the true string while a count is in flight. Reading
 *    textContent mid-count would make the arc chase a number that is itself an
 *    animation.
 *
 * 2. THE WIDGET POLLS EVERY 10s AND LIVEWIRE MORPHS. A morph removes DOM it did
 *    not render, which includes everything injected here. So nothing is injected
 *    once and trusted: a MutationObserver reconciles the gauges back into the
 *    cards, and the reconcile is idempotent -- it re-mounts what is missing,
 *    animates only what actually changed, and never replays the intro.
 *
 * 3. THE FAILURE MODE MUST BE "NO RING", NEVER "WRONG RING". A gauge is mounted
 *    already drawn at its true value but invisible, and the intro is the only
 *    thing that reveals it. If the reveal never happens -- a ScrollTrigger that
 *    mis-measured, a widget that arrived late -- the operator sees no ring at
 *    all rather than an empty ring next to "87%". Two timed failsafes reveal
 *    anything still hidden while on screen.
 *
 * 4. THE RING CARRIES NO INFORMATION THE TEXT DOES NOT. It is `aria-hidden` and
 *    `pointer-events: none`: the label, the value and the threshold colour are
 *    all already in the accessibility tree, and a screen reader announcing a
 *    decorative arc would only add noise.
 *
 * 5. NOTHING PERSISTENT IS LEFT ON A CARD. The injected wrapper is absolutely
 *    positioned and contains no controls, so its transient transform cannot
 *    become the containing block for a Filament action modal; the card itself is
 *    never transformed.
 */

/* Marks every node this module appended, so a run that was interrupted before
   its disposers ran can still be swept by attribute on the next boot. */
const OWNED = 'data-vf-arc'

/* Marks the stat card hosting a gauge. Only used to lift the card's own content
   above the ring in the paint order. */
const HOST = 'data-vf-arc-host'

const STYLE_ID = 'vf-health-arcs'

/* counters.js parks the true, server-rendered string here for as long as it owns
   the value text, and flags the element itself while the count is in flight.
   While that flag is present, textContent is a frame of an animation and must
   neither be read as a reading nor treated as a change worth reacting to. */
const COUNT_FROM = 'data-vf-count-from'
const COUNTING = 'data-vf-counting'

/* Ring geometry, in the SVG's own 100x100 user space. A 270deg arc with the gap
   at the bottom is the shape people already read as a gauge; a full circle reads
   as a progress donut, which is a different claim. */
const RADIUS = 42
const CIRCUMFERENCE = 2 * Math.PI * RADIUS
const START_DEGREES = 135
const SWEEP_DEGREES = 270
const ARC_LENGTH = (CIRCUMFERENCE * SWEEP_DEGREES) / 360

const SVG_NS = 'http://www.w3.org/2000/svg'

/* Bounded work: a page with more stat widgets than this is not a dashboard, and
   scanning them all on every poll would cost more than the gauges say. */
const MAX_WIDGETS = 4
const MAX_CARDS = 24

/* Seconds after which a gauge that is on screen but still hidden is revealed
   without ceremony. Two passes, the second late enough to catch a widget whose
   content Livewire deferred past the first. */
const FAILSAFE_EARLY = 4.5
const FAILSAFE_LATE = 9

/* A stat only qualifies for a gauge if its description carries one of the
   widget's own threshold colours. That is the panel telling us this number is
   measured against a scale -- the neutral cards (counts, uptime, PHP version)
   are quantities with no ceiling and have nothing to sweep to. */
const BANDS = ['danger', 'warning', 'success']

/* "13%" -- the memory and disk readings. Anything with a prefix, a unit or a
   second number is not a percentage of a whole and is refused. */
const PERCENT = /^([+-]?\d+(?:[.,]\d+)?)\s*%$/

/* "0.08" -- a bare load average, exactly as number_format(x, 2) emits it. */
const LOAD = /^\d+(?:[.,]\d+)?$/

/*
 * The CPU card's signature. Its description is built from a translated string
 * that keeps the literal "5 min" and "15 min" markers in every locale shipped
 * with this panel (fr, de, es, it, pt), so requiring both is a language-
 * independent way to recognise it -- and, more importantly, a way to be sure a
 * bare number like "3" on some other coloured stat is never mistaken for a load
 * average. `\b5` cannot match inside "15", so the two tests stay distinct.
 */
const LOAD_5 = /\b5\s*min\b/i
const LOAD_15 = /\b15\s*min\b/i

/*
 * The leading core count: "2 cores - ...", "2 coeurs - ...", "2 Kerne - ...".
 * The trailing separator (U+00B7, written escaped so this file survives any
 * encoding) is what makes this safe -- without it, the coreless variant of the
 * same description ("5 min 0.05 - 15 min 0.01") would read its own "5" as a core
 * count. The negative lookahead is a second line of defence for the same case,
 * and a description this does not match simply falls back to one core, which is
 * the widget's own fallback rather than a different guess.
 */
const CORE_COUNT = /^(\d{1,4})\s+(?!min\b)\S+\s*\u00B7/i

const CSS = `
.vf-arc {
    position: absolute;
    top: 1.1rem;
    inset-inline-end: 1.1rem;
    width: 4rem;
    height: 4rem;
    pointer-events: none;

    /* Filament's own palette, read from the variables it writes on :root, so a
       gauge cannot drift away from the colour of the description it belongs to.
       The literals are only reached if that palette is ever missing. */
    --vf-arc-ink: var(--gray-400, #9ca3af);
}

.vf-arc > svg {
    display: block;
    width: 100%;
    height: 100%;
}

.vf-arc-track,
.vf-arc-value {
    fill: none;
    stroke: var(--vf-arc-ink);
    stroke-width: 6;
    stroke-linecap: round;
}

.vf-arc-track {
    opacity: 0.15;
}

/* Only the colour transitions. stroke-dashoffset is driven frame by frame from
   JS, and a CSS transition on the same property would re-interpolate every value
   written and smear the sweep into a lag. */
.vf-arc-value,
.vf-arc-tip > circle {
    transition-property: stroke, fill;
    transition-duration: 320ms;
    transition-timing-function: var(--vf-ease-soft, ease);
}

.vf-arc-tip > circle {
    fill: var(--vf-arc-ink);
    stroke: none;
}

.vf-arc-success { --vf-arc-ink: var(--success-500, #22c55e); }
.vf-arc-warning { --vf-arc-ink: var(--warning-500, #f59e0b); }
.vf-arc-danger { --vf-arc-ink: var(--danger-500, #ef4444); }

/* Dark mode takes the 400 shades, matching what Filament's own description text
   does one line below the ring. Declared after the light rules so equal
   specificity resolves in their favour. */
.dark .vf-arc { --vf-arc-ink: var(--gray-500, #6b7280); }
.dark .vf-arc-track { opacity: 0.22; }
.dark .vf-arc-success { --vf-arc-ink: var(--success-400, #4ade80); }
.dark .vf-arc-warning { --vf-arc-ink: var(--warning-400, #fbbf24); }
.dark .vf-arc-danger { --vf-arc-ink: var(--danger-400, #f87171); }

/* The ring is absolutely positioned, so it would otherwise paint over the card's
   own text on a narrow card. The value is the reason the card exists. */
.fi-wi-stats-overview-stat[${HOST}] > .fi-wi-stats-overview-stat-content {
    position: relative;
    z-index: 1;
}
`

/*
 * Teardown. gsap.context() reverts tweens; it does not remove appended nodes,
 * disconnect observers, cancel frames or drop listeners. Every one of those is
 * registered here, run at the start of the next boot, and also run from a nested
 * context so the runtime's own revert() reaches it.
 */
let disposers = []

function runDisposers() {
    while (disposers.length) {
        const dispose = disposers.pop()

        try {
            dispose()
        } catch (error) {
            // A failing disposer must not strand the ones behind it.
        }
    }
}

function clamp01(value) {
    if (!(value > 0)) {
        return 0
    }

    return value > 1 ? 1 : value
}

/**
 * Which threshold band the widget assigned this stat, from the classes Filament
 * puts on the description element. `gray` resolves to no class at all (the
 * description component declares gray its default), which is exactly the signal
 * we want: an unavailable reading gets no gauge.
 *
 * @param {Element} description
 * @returns {?string}
 */
function bandOf(description) {
    for (const band of BANDS) {
        if (description.classList.contains(`fi-color-${band}`)) {
            return band
        }
    }

    return null
}

/**
 * Turn a rendered stat value into a fraction of its scale, or null if this is
 * not something with a scale. Guilty until proven innocent: every shape that is
 * not one of the two this widget emits is refused rather than guessed at.
 *
 * @param {string} value  the value exactly as rendered
 * @param {string} description  the description text, whitespace-collapsed
 * @returns {?number}
 */
function fractionOf(value, description) {
    const percent = value.match(PERCENT)

    if (percent) {
        const number = Number(percent[1].replace(',', '.'))

        return Number.isFinite(number) ? clamp01(number / 100) : null
    }

    // Everything below is the CPU load card and only the CPU load card.
    if (!LOAD.test(value) || !LOAD_5.test(description) || !LOAD_15.test(description)) {
        return null
    }

    const load = Number(value.replace(',', '.'))

    if (!Number.isFinite(load)) {
        return null
    }

    // Load per core, which is the ratio the widget itself colours against: at
    // 1.0 per core the box is saturated, and that is also where the widget turns
    // the card red. A full ring therefore means "at the danger line", and the
    // clamp above it is honest -- past saturation the needle is against its stop.
    // With no core count in the description the denominator is 1, matching the
    // widget's own fallback rather than inventing a different one.
    const cores = description.match(CORE_COUNT)
    const denominator = cores ? Math.min(1024, Math.max(1, Number(cores[1]))) : 1

    return clamp01(load / denominator)
}

/**
 * Read one stat card. Returns null for every card that is not an instrument.
 *
 * @param {Element} card
 * @returns {?{value: string, band: string, fraction: number}}
 */
function readCard(card) {
    const valueNode = card.querySelector('.fi-wi-stats-overview-stat-value')
    const descriptionNode = card.querySelector('.fi-wi-stats-overview-stat-description')

    if (!valueNode || !descriptionNode) {
        return null
    }

    const band = bandOf(descriptionNode)

    if (!band) {
        return null
    }

    // counters.js's parked original wins over textContent: while it is present
    // the text on screen is an intermediate frame of its count-up, and an arc
    // chasing that would be animating an animation.
    const parked = valueNode.getAttribute(COUNT_FROM)
    const value = (parked === null ? valueNode.textContent || '' : parked).trim()

    if (value === '') {
        return null
    }

    const description = (descriptionNode.textContent || '').replace(/\s+/g, ' ').trim()
    const fraction = fractionOf(value, description)

    if (fraction === null) {
        return null
    }

    return { value, band, fraction }
}

function ring(className) {
    const circle = document.createElementNS(SVG_NS, 'circle')

    circle.setAttribute('class', className)
    circle.setAttribute('cx', '50')
    circle.setAttribute('cy', '50')
    circle.setAttribute('r', String(RADIUS))
    // Rolls the path's 3 o'clock origin round to the bottom-left, which puts the
    // 90deg gap at the bottom where a dial's gap belongs.
    circle.setAttribute('transform', `rotate(${START_DEGREES} 50 50)`)
    // One dash the length of the arc, then a gap longer than the whole circle,
    // so only ever one dash is painted no matter where the offset sits.
    circle.setAttribute('stroke-dasharray', `${ARC_LENGTH.toFixed(3)} ${CIRCUMFERENCE.toFixed(3)}`)
    circle.style.strokeDashoffset = '0'

    return circle
}

function buildRing() {
    const root = document.createElement('div')

    root.className = 'vf-arc'
    root.setAttribute(OWNED, '')
    // The label, the value and the threshold colour are all already exposed as
    // text one line away. The ring restates them and nothing more.
    root.setAttribute('aria-hidden', 'true')

    const svg = document.createElementNS(SVG_NS, 'svg')
    svg.setAttribute('viewBox', '0 0 100 100')
    svg.setAttribute('focusable', 'false')
    svg.setAttribute('aria-hidden', 'true')

    const track = ring('vf-arc-track')
    const value = ring('vf-arc-value')

    // The needle head. Wrapped in a group so its position is one rotation about
    // the dial's centre rather than a pair of trigonometric writes.
    const tip = document.createElementNS(SVG_NS, 'g')
    tip.setAttribute('class', 'vf-arc-tip')

    const dot = document.createElementNS(SVG_NS, 'circle')
    dot.setAttribute('cx', String(50 + RADIUS))
    dot.setAttribute('cy', '50')
    dot.setAttribute('r', '4')
    tip.appendChild(dot)

    svg.appendChild(track)
    svg.appendChild(value)
    svg.appendChild(tip)
    root.appendChild(svg)

    return { root, track, value, tip }
}

/**
 * Server health arc gauges.
 *
 * @param {{gsap: import('gsap').gsap, ScrollTrigger: object, MOTION: object}} api
 * @returns {null}
 */
export function healthGauges({ gsap, ScrollTrigger, MOTION }) {
    if (typeof document === 'undefined' || !document.body) {
        return null
    }

    // Undo the previous run first, then sweep by attribute in case that run was
    // interrupted before its disposers could be registered.
    runDisposers()
    document.querySelectorAll(`[${OWNED}]`).forEach((node) => node.remove())
    document.querySelectorAll(`[${HOST}]`).forEach((node) => node.removeAttribute(HOST))

    const widgets = gsap.utils.toArray('.fi-wi-stats-overview').slice(0, MAX_WIDGETS)

    if (!widgets.length) {
        return null
    }

    const tier = window.__vfMotion?.tier ?? 'medium'
    const reduced = window.__vfMotion?.reduced === true

    // At the bottom tier the gauge still gets drawn -- it is information, and a
    // static ring costs one paint -- but nothing about it moves.
    const still = reduced || tier === 'low'
    const rich = tier === 'high'

    /*
     * `gsap.context()` with no argument hands back the context we are currently
     * running inside -- the runtime's. Tweens created later, from a mutation
     * observer or a scroll trigger, would otherwise belong to no context at all:
     * the runtime's revert() would not kill them, and they would go on writing
     * to nodes it has already torn down. Routing them through `add()` puts them
     * back under its control.
     */
    const host = gsap.context()

    const own = (create) => {
        let result

        if (host) {
            host.add(() => {
                result = create()
            })
        } else {
            result = create()
        }

        return result
    }

    // A nested context exists purely so the runtime's revert() reaches the
    // disposers above; a returned function is registered by GSAP as cleanup.
    gsap.context(() => () => runDisposers())

    let stylesheet = null

    // Injected on the first gauge actually built, not on sight of a stats
    // widget: most pages in this panel have one and no gauge in it.
    function ensureStylesheet() {
        if (stylesheet) {
            return
        }

        const stale = document.getElementById(STYLE_ID)

        if (stale) {
            stale.remove()
        }

        stylesheet = document.createElement('style')
        stylesheet.id = STYLE_ID
        stylesheet.setAttribute(OWNED, '')
        stylesheet.textContent = CSS
        document.head.appendChild(stylesheet)

        disposers.push(() => {
            stylesheet.remove()
            stylesheet = null
        })
    }

    widgets.forEach((widget) => attach(widget))

    /**
     * Everything for one stats widget: its gauges, the observer that keeps them
     * mounted, and the triggers that reveal them.
     *
     * @param {Element} widget
     */
    function attach(widget) {
        /* Keyed by the stat's index within the widget rather than by its element:
           a Livewire morph can replace the card node itself, and the reading it
           carries is still the same slot of the same read-out. Keying by index is
           what lets a gauge survive that replacement without replaying its
           intro. */
        const gauges = new Map()

        let frame = 0
        let revealed = false
        /* Assume on screen until an IntersectionObserver says otherwise, so a
           browser without one animates rather than never animating. */
        let onScreen = true

        const canAnimate = () => !still && onScreen && !document.hidden

        /* --- drawing ------------------------------------------------------ */

        /**
         * Write the current fraction to both the arc and the needle from one
         * place, so the two can never disagree about where the reading is.
         *
         * The clamp is deliberate: an overshoot that would take the arc past the
         * end of its scale instead holds it at full, which is what a real needle
         * does against its stop. Nothing here reads layout.
         */
        function draw(gauge) {
            const f = clamp01(gauge.state.f)

            gauge.value.style.strokeDashoffset = (ARC_LENGTH * (1 - f)).toFixed(2)
            gauge.tip.setAttribute(
                'transform',
                `rotate(${(START_DEGREES + SWEEP_DEGREES * f).toFixed(2)} 50 50)`,
            )
        }

        function paintBand(gauge) {
            gauge.root.classList.remove('vf-arc-success', 'vf-arc-warning', 'vf-arc-danger')

            if (gauge.band) {
                gauge.root.classList.add(`vf-arc-${gauge.band}`)
            }
        }

        /**
         * End the intro immediately and leave the ring in exactly the resting
         * state the intro would have produced. Needed because the intro drives
         * three things at once -- the wrapper, the dial and the needle -- and
         * anything that takes the needle away from it mid-flight would otherwise
         * leave the other two frozen part-way.
         */
        function settleIntro(gauge) {
            if (!gauge.intro) {
                return
            }

            if (gauge.intro.isActive()) {
                gauge.intro.kill()
                gauge.track.style.strokeDashoffset = '0'
                gsap.set(gauge.root, {
                    autoAlpha: 1,
                    clearProps: 'transform,transformOrigin',
                })
            }

            gauge.intro = null
        }

        /** Put the needle on a value with no motion at all. */
        function park(gauge, fraction) {
            settleIntro(gauge)
            gauge.sweep?.kill()
            gauge.sweep = null
            gauge.state.f = fraction
            draw(gauge)
        }

        /* --- mounting ----------------------------------------------------- */

        function create(index) {
            ensureStylesheet()

            const parts = buildRing()

            return {
                index,
                card: null,
                root: parts.root,
                track: parts.track,
                value: parts.value,
                tip: parts.tip,
                state: { f: 0 },
                target: 0,
                reading: null,
                band: null,
                shown: false,
                intro: null,
                sweep: null,
            }
        }

        /**
         * Attach (or re-attach) a gauge to its card. Called on first build and
         * again whenever a morph has taken the ring away or replaced the card
         * underneath it.
         */
        function mount(gauge, card) {
            if (gauge.card && gauge.card !== card) {
                gauge.card.removeAttribute(HOST)
            }

            gauge.card = card
            card.setAttribute(HOST, '')
            card.appendChild(gauge.root)

            paintBand(gauge)
            // Drawn before it is ever visible, so a re-mount cannot show a frame
            // of empty ring next to a value that is already on screen.
            draw(gauge)

            own(() => gsap.set(gauge.root, { autoAlpha: gauge.shown ? 1 : 0 }))

            // A morph that lands mid-intro leaves the intro tweening a node that
            // is no longer in the document. Restart it on the node that is.
            if (gauge.shown && gauge.intro && gauge.intro.isActive()) {
                gauge.intro.kill()
                gauge.intro = null
                gauge.shown = false
                reveal(gauge, canAnimate())
            }
        }

        function destroy(gauge) {
            gauge.intro?.kill()
            gauge.sweep?.kill()
            gauge.intro = null
            gauge.sweep = null
            gauge.root.remove()

            if (gauge.card) {
                gauge.card.removeAttribute(HOST)
                gauge.card = null
            }
        }

        /* --- the reveal --------------------------------------------------- */

        /**
         * The set piece. The dial is drawn, then the needle is flung out to the
         * reading and settles back onto it.
         *
         * `back.out` is what makes it read as a measurement rather than a fill:
         * it overshoots by about 7% of the distance travelled and damps back.
         * That scales itself correctly for free -- a first reading swings hard,
         * a two-point change on a poll barely twitches.
         *
         * @param {object} gauge
         * @param {boolean} animated
         */
        function reveal(gauge, animated) {
            if (gauge.shown || !gauge.root.isConnected) {
                return
            }

            gauge.shown = true

            if (!animated) {
                park(gauge, gauge.target)
                own(() => gsap.to(gauge.root, {
                    autoAlpha: 1,
                    // A rescued or off-screen gauge still gets a short fade, but
                    // at the bottom tier "no motion" has to mean none at all.
                    duration: still ? 0 : MOTION.fast,
                    ease: MOTION.easeSoft,
                }))

                return
            }

            // Dropped before the collapse below, not settled: this intro is
            // about to be replaced by a fresh one, so there is nothing to leave
            // in a resting state.
            gauge.intro?.kill()
            gauge.intro = null

            // Collapsed only now, one frame before the reveal starts, and while
            // the ring is still invisible -- an empty gauge is never on screen.
            park(gauge, 0)

            gauge.intro = own(() => {
                // The same per-card stagger counters.js uses, so the needle and
                // the digits of one card move as a single reading rather than as
                // two effects that happen to overlap.
                const timeline = gsap.timeline({
                    delay: Math.min(gauge.index, 6) * MOTION.stagger,
                })

                timeline.fromTo(
                    gauge.root,
                    { scale: 0.72 },
                    {
                        scale: 1,
                        duration: MOTION.base,
                        ease: MOTION.spring,
                        transformOrigin: '50% 50%',
                        // The transform is transient by rule: an element holding
                        // one becomes the containing block for any fixed-position
                        // descendant, and Filament renders action modals inline
                        // inside these cards.
                        clearProps: 'transform,transformOrigin',
                    },
                    0,
                )

                // Opacity rides a separate, non-overshooting ease: a spring on
                // alpha would push it past 1 on the way, and the ring should be
                // legible while it is still settling.
                timeline.fromTo(
                    gauge.root,
                    { autoAlpha: 0 },
                    { autoAlpha: 1, duration: MOTION.fast, ease: MOTION.easeSoft },
                    0,
                )

                if (rich) {
                    // The dial is drawn before the needle takes its reading.
                    // Skipped below the top tier: it is the one purely scenic
                    // beat in the sequence.
                    timeline.fromTo(
                        gauge.track,
                        { strokeDashoffset: ARC_LENGTH },
                        { strokeDashoffset: 0, duration: 0.5, ease: MOTION.ease },
                        0.04,
                    )
                }

                timeline.to(
                    gauge.state,
                    {
                        f: gauge.target,
                        duration: MOTION.slow * 0.8,
                        ease: MOTION.spring,
                        onUpdate: () => draw(gauge),
                    },
                    rich ? 0.3 : 0.14,
                )

                return timeline
            })
        }

        function revealAll(animated) {
            revealed = true
            gauges.forEach((gauge) => reveal(gauge, animated))
        }

        /**
         * One short pulse when a reading crosses into the red. Not decoration:
         * the colour change alone is easy to miss on a page you are not looking
         * at, and this is the panel saying it just crossed a line. One shot,
         * bounded, and it leaves nothing behind.
         */
        function alarm(gauge) {
            own(() => gsap.fromTo(
                gauge.root,
                { scale: 1 },
                {
                    scale: 1.14,
                    duration: 0.17,
                    ease: 'power2.out',
                    repeat: 1,
                    yoyo: true,
                    transformOrigin: '50% 50%',
                    overwrite: 'auto',
                    clearProps: 'transform,transformOrigin',
                },
            ))
        }

        /* --- reconciliation ----------------------------------------------- */

        /**
         * Bring one gauge in line with what its card currently says. Only a
         * genuine change to the rendered value moves the needle -- a poll that
         * returns the same reading must not re-animate anything, or the page
         * twitches every ten seconds for no reason.
         */
        function update(gauge, reading) {
            if (gauge.band !== reading.band) {
                const escalated = gauge.band !== null && reading.band === 'danger'

                gauge.band = reading.band
                paintBand(gauge)

                // Never during the intro: the intro already owns the wrapper's
                // transform, and the ring arriving in red is loud enough.
                const busy = Boolean(gauge.intro && gauge.intro.isActive())

                if (escalated && gauge.shown && canAnimate() && !busy) {
                    alarm(gauge)
                }
            }

            if (gauge.reading === reading.value) {
                return
            }

            const first = gauge.reading === null

            gauge.reading = reading.value
            gauge.target = reading.fraction

            // Either nothing has been revealed yet -- so there is no motion to
            // preserve and the reveal will find the needle already on the new
            // reading -- or this is not a moment worth animating.
            if (first || !gauge.shown || !canAnimate()) {
                park(gauge, reading.fraction)

                return
            }

            // A poll landing inside the intro is rare but possible on a slow
            // first paint, and two things driving the same needle would fight.
            // The intro yields; the newer reading wins.
            settleIntro(gauge)
            gauge.sweep?.kill()

            gauge.sweep = own(() => gsap.to(gauge.state, {
                f: reading.fraction,
                duration: MOTION.base,
                ease: MOTION.spring,
                onUpdate: () => draw(gauge),
            }))
        }

        function reconcile() {
            if (!widget.isConnected) {
                return
            }

            const cards = Array.from(
                widget.querySelectorAll('.fi-wi-stats-overview-stat'),
            ).slice(0, MAX_CARDS)

            const live = new Set()

            cards.forEach((card, index) => {
                const reading = readCard(card)

                if (!reading) {
                    return
                }

                live.add(index)

                let gauge = gauges.get(index)

                if (!gauge) {
                    gauge = create(index)
                    gauges.set(index, gauge)
                }

                if (gauge.card !== card || !gauge.root.isConnected) {
                    mount(gauge, card)
                } else if (!card.hasAttribute(HOST)) {
                    // A morph can strip an attribute it did not render while
                    // leaving the node it sits on alone.
                    card.setAttribute(HOST, '')
                }

                update(gauge, reading)

                // A stat that only becomes an instrument later -- a CPU reading
                // recovering from "--" -- has missed the widget's one reveal, so
                // it gets its own.
                if (revealed && !gauge.shown) {
                    reveal(gauge, canAnimate())
                }
            })

            // A card that stopped qualifying (its reading went unavailable) loses
            // its ring rather than keeping a stale one.
            gauges.forEach((gauge, index) => {
                if (live.has(index)) {
                    return
                }

                destroy(gauge)
                gauges.delete(index)
            })
        }

        /* --- wiring ------------------------------------------------------- */

        /*
         * The widget polls every 10s and Livewire morphs the result in, which
         * removes the rings and rewrites the values. There is no event that
         * reports both, so the DOM is watched directly.
         *
         * Attributes are deliberately NOT observed: every frame of every sweep
         * writes a style attribute and a transform attribute of our own, and
         * observing those would put the reconcile on the animation's critical
         * path. childList and characterData cover everything a morph does that
         * matters here -- and our own insertions happen with the observer
         * disconnected, so it cannot retrigger itself.
         */
        const observer = new MutationObserver((records) => {
            if (frame || !records.some(worthReading)) {
                return
            }

            frame = requestAnimationFrame(() => {
                frame = 0
                sync()
            })
        })

        /**
         * counters.js rewrites a stat's text on every frame of its count-up.
         * Those are real character-data mutations, and reacting to them would
         * mean a full reconcile per frame for the first second of the page --
         * exactly when the entrance sequence needs the main thread. The element
         * it flags while it owns the text is the signal to sit them out; the
         * reading behind them is unchanged anyway.
         */
        function worthReading(record) {
            if (record.type !== 'characterData') {
                return true
            }

            const owner = record.target.parentElement

            return !(owner && owner.hasAttribute(COUNTING))
        }

        function sync() {
            observer.disconnect()

            try {
                reconcile()
            } finally {
                if (widget.isConnected) {
                    observer.observe(widget, {
                        childList: true,
                        subtree: true,
                        characterData: true,
                    })
                }
            }
        }

        sync()

        // Nothing qualified: leave no observer, no trigger and no listener behind
        // on a widget that has no instrument in it.
        if (!gauges.size) {
            observer.disconnect()

            return
        }

        disposers.push(() => {
            observer.disconnect()

            if (frame) {
                cancelAnimationFrame(frame)
                frame = 0
            }

            gauges.forEach((gauge) => destroy(gauge))
            gauges.clear()
        })

        if (typeof IntersectionObserver === 'function') {
            // Off-screen readings still have to be correct, they just do not have
            // to be animated. This is also what keeps a poll landing on a widget
            // scrolled far out of view from costing anything.
            const visibility = new IntersectionObserver(
                (entries) => {
                    entries.forEach((entry) => {
                        onScreen = entry.isIntersecting
                    })
                },
                { rootMargin: '120px' },
            )

            visibility.observe(widget)
            disposers.push(() => visibility.disconnect())
        }

        if (still) {
            revealAll(false)

            return
        }

        // A hidden tab throttles rAF, so an in-flight sweep would otherwise
        // resume minutes later against a reading that has since been replaced
        // several times over. Snap everything to its current value instead.
        const onVisibility = () => {
            if (!document.hidden) {
                return
            }

            gauges.forEach((gauge) => {
                if (!gauge.shown) {
                    return
                }

                // park() settles the intro properly on its way through; killing
                // it first would strand the wrapper part-way through its scale.
                park(gauge, gauge.target)
                gsap.set(gauge.root, { autoAlpha: 1 })
            })
        }

        document.addEventListener('visibilitychange', onVisibility)
        disposers.push(() => document.removeEventListener('visibilitychange', onVisibility))

        // Without ScrollTrigger there is no first-view gate to wait for, and a
        // gauge that never reveals is a gauge that was never worth injecting.
        if (!ScrollTrigger) {
            revealAll(canAnimate())

            return
        }

        /* First view. The threshold sits at 97% of the viewport, matching
           counters.js: a gauge that has not been drawn yet is a plausible-looking
           wrong reading, so it is only ever adopted for cards within a hair of
           being on screen. */
        ScrollTrigger.create({
            trigger: widget,
            start: 'top 97%',
            once: true,
            onEnter: () => revealAll(canAnimate()),
        })

        /*
         * The failsafes. Every ring is mounted already drawn at its true value
         * and merely invisible, so the worst a missed trigger can do is leave a
         * gauge off the page -- never an empty ring beside a full reading. These
         * two passes close even that gap for anything that is demonstrably on
         * screen. Off-screen widgets are left to their trigger, which is still
         * armed and will fire when they are scrolled to.
         */
        const rescue = () => {
            if (revealed || !onScreen || !widget.isConnected) {
                return
            }

            revealAll(false)
        }

        gsap.delayedCall(FAILSAFE_EARLY, rescue)
        gsap.delayedCall(FAILSAFE_LATE, rescue)
    }

    return null
}
