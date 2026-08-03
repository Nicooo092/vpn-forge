/**
 * Data coming alive.
 * -----------------
 * This panel is mostly measurements, so the one place motion earns its keep is
 * the moment a measurement lands: numbers count up to their value, gauges sweep
 * out from zero, the bandwidth chart wipes in left to right. Nothing here is
 * decoration -- each effect is the panel showing you it just took a reading.
 *
 * The hard part is not the animation, it is the parsing. Filament stat values
 * are already-formatted STRINGS by the time they reach the DOM: "0.08", "13%",
 * "2 / 2", "1.9 GB", "2d 0h", "8.3.33". Counting one up means taking it apart,
 * animating the number and putting it back together byte for byte. A roll-up
 * that renders "1,018" as "1018" halfway through has corrupted a reading an
 * operator is trying to act on, which is far worse than no animation at all.
 *
 * So the parser is guilty until proven innocent: it reconstructs the original
 * string from its own parse and refuses the element unless the reconstruction
 * is character-identical (see `describe`). Anything it cannot reproduce exactly
 * -- versions, dates, durations, ambiguous separators -- is left alone.
 *
 * Safety rules this file holds itself to:
 *   - A value is only ever overwritten while a tween owns it, and every path out
 *     of that tween (complete, interrupt, re-boot, wall-clock fallback) writes
 *     the original string back.
 *   - Nothing is set to a wrong-looking state before it is on screen. An
 *     off-screen gauge is never pre-collapsed to zero and an off-screen stat is
 *     never pre-zeroed; both keep their true value until the frame they enter.
 *   - Only transform, opacity, filter and clip-path are animated.
 *
 * @param {{gsap: import('gsap').gsap, ScrollTrigger: object, MOTION: object}} api
 */

/*
 * Every deferred callback in here (rAF gates, wall-clock fallbacks) captures the
 * boot it belongs to. `livewire:navigated` and a reduced-motion toggle both
 * re-run the module, and a callback from the previous run writing to an element
 * the current run is animating would fight it. Stale generations bail out.
 */
let generation = 0

/* Marks an element whose text is currently owned by a tween. Also the signal the
   next boot uses to heal anything left mid-count. */
const IN_FLIGHT = 'data-vf-counting'

/* The exact original string, kept in the DOM so a *different* boot can restore
   it -- the closure that knows it is gone by then. */
const ORIGINAL = 'data-vf-count-from'

/* First-appearance-only: once claimed, an element is never animated again. */
const CLAIMED = 'data-vf-counted'

/* Characters this panel can legitimately use between digits. Comma from PHP's
   number_format, the three space variants from locale-aware formatters, dot for
   the decimal. */
const SEPARATOR = /[.,   ]/

/* The leading numeric token: digits, optionally with separator-joined digit runs
   after it. Deliberately greedy across separators so "8.3.33" arrives here as
   one token and gets rejected as a whole, rather than being read as "8.3". */
const TOKEN = /[+-]?\d+(?:[.,   ]\d+)*/

/* A number immediately followed by one of these and another digit is a date, a
   time or a version, never a quantity: "2026-08-03", "14:05", "3, 2026". */
const STRUCTURED = /^[-/:.,]\s*\d/

/* A number whose unit is a time word is an age or a duration -- "3 days",
   "2 heures". Rolling those up reads as a clock running, which is a lie about
   what the panel just did. Only the START of the suffix is checked so genuine
   units ("1018 MB of 7.8 GB used") and compact durations ("2d 0h") survive. */
const TIME_UNIT =
    /^\s*(ago|seconds?|secs?|minutes?|mins?|hours?|hrs?|days?|weeks?|months?|years?|secondes?|heures?|jours?|semaines?|mois|ann[ée]es?|ans?)\b/i

/* Semantic versions look exactly like grouped decimals to a naive parser. */
const VERSION = /^\d+\.\d+\.\d+/

/* Prefixes are for symbols -- "$", "~", "<". A letter in front of the number
   means the number is embedded in prose and the string is not a quantity. */
const SYMBOL_ONLY = /^[^\p{L}\p{N}]{0,4}$/u

/**
 * Take a formatted string apart into something that can be re-rendered at any
 * intermediate value. Returns null for anything it is not certain about.
 *
 * @param {string} raw
 * @returns {?{value: number, prefix: string, suffix: string, sign: string, decimals: number, group: string, point: string}}
 */
function describe(raw) {
    const text = raw.trim()

    if (text === '' || VERSION.test(text)) {
        return null
    }

    const found = text.match(TOKEN)

    if (!found) {
        return null
    }

    const token = found[0]
    const prefix = text.slice(0, found.index)
    const suffix = text.slice(found.index + token.length)

    // A prefix ending in a separator means the match started mid-number (".5",
    // the tail of something the token regex would not take whole).
    if (SEPARATOR.test(prefix.slice(-1))) {
        return null
    }

    if (!SYMBOL_ONLY.test(prefix) || STRUCTURED.test(suffix) || TIME_UNIT.test(suffix)) {
        return null
    }

    const sign = token[0] === '-' || token[0] === '+' ? token[0] : ''
    const body = sign ? token.slice(1) : token
    const parts = body.split(SEPARATOR)
    const separators = body.match(new RegExp(SEPARATOR, 'g')) ?? []

    // "08" is a padded field (an hour, a minute), not a count -- toFixed would
    // drop the zero and silently rewrite it.
    if (parts[0].length > 1 && parts[0][0] === '0') {
        return null
    }

    const shape = shapeOf(parts, separators)

    if (!shape) {
        return null
    }

    const { digits, fraction, group } = shape
    const value = Number(`${sign === '-' ? '-' : ''}${digits}${fraction ? `.${fraction}` : ''}`)

    if (!Number.isFinite(value) || value === 0) {
        return null
    }

    const format = {
        value,
        prefix,
        suffix,
        sign: sign === '+' ? '+' : '',
        decimals: fraction.length,
        group,
        point: shape.point,
    }

    // The proof. If re-rendering the parsed value does not reproduce the string
    // the server sent, character for character, the parse is wrong about
    // something and this element is not touched.
    return render(value, format) === text ? format : null
}

/**
 * Classify the digit runs of a numeric token: which separator (if any) groups
 * thousands, which one marks the decimal point, and what the digits actually
 * are. Rejects every shape that is not one of the small set this panel emits.
 *
 * @param {string[]} parts
 * @param {string[]} separators
 * @returns {?{digits: string, fraction: string, group: string, point: string}}
 */
function shapeOf(parts, separators) {
    const grouped = (index) => parts[index].length === 3
    const leads = parts[0].length >= 1 && parts[0].length <= 3

    if (separators.length === 0) {
        return { digits: parts[0], fraction: '', group: '', point: '.' }
    }

    if (separators.length === 1) {
        const [head, tail] = parts

        if (separators[0] === '.') {
            // "1.234" is a decimal here and thousands-grouped in half of Europe.
            // Nothing in this panel formats to three decimal places, so refusing
            // the ambiguous shape costs nothing and removes the guess.
            if (head.length <= 3 && tail.length === 3) {
                return null
            }

            return { digits: head, fraction: tail, group: '', point: '.' }
        }

        // A comma or a space followed by exactly three digits is a thousands
        // group. Anything else with those separators (a European decimal comma,
        // say) is ambiguous and refused.
        if (leads && grouped(1)) {
            return { digits: head + tail, fraction: '', group: separators[0], point: '.' }
        }

        return null
    }

    const first = separators[0]
    const last = separators[separators.length - 1]
    const allSame = separators.every((separator) => separator === first)

    // 1 234 567 -- every separator groups, nothing is a decimal point.
    if (allSame && leads && parts.slice(1).every((_, index) => grouped(index + 1))) {
        return { digits: parts.join(''), fraction: '', group: first, point: '.' }
    }

    // 1,234.56 or 1 234,56 -- all but the last separator group, the last one is
    // the decimal point and must differ from the grouping character.
    const groupsThenPoint =
        last !== first &&
        (last === '.' || last === ',') &&
        separators.slice(0, -1).every((separator) => separator === first) &&
        leads &&
        parts.slice(1, -1).every((_, index) => grouped(index + 1))

    if (groupsThenPoint) {
        return {
            digits: parts.slice(0, -1).join(''),
            fraction: parts[parts.length - 1],
            group: first,
            point: last,
        }
    }

    return null
}

/**
 * Rebuild the display string for an arbitrary value in the format the original
 * used. Rounding happens through toFixed at the original precision, so every
 * frame of the tween is formatted exactly the way the final frame will be --
 * there is no moment where the number on screen is formatted differently from
 * the number the server sent.
 *
 * @param {number} value
 * @param {{prefix: string, suffix: string, sign: string, decimals: number, group: string, point: string}} format
 * @returns {string}
 */
function render(value, format) {
    const fixed = value.toFixed(format.decimals)
    const negative = fixed[0] === '-'
    const [whole, fraction] = (negative ? fixed.slice(1) : fixed).split('.')

    const digits = format.group
        ? whole.replace(/\B(?=(\d{3})+(?!\d))/g, format.group)
        : whole

    return (
        format.prefix +
        (negative ? '-' : format.sign) +
        digits +
        (fraction === undefined ? '' : format.point + fraction) +
        format.suffix
    )
}

/**
 * Restore anything the previous boot left mid-count. Only elements still flagged
 * in-flight are touched: a stat that finished counting may since have been
 * re-polled with a NEW value, and writing our remembered string over that would
 * put a stale reading back on screen.
 */
function healInterrupted() {
    document.querySelectorAll(`[${IN_FLIGHT}]`).forEach((element) => {
        const original = element.getAttribute(ORIGINAL)

        if (original !== null) {
            element.textContent = original
        }

        element.removeAttribute(IN_FLIGHT)
    })
}

/**
 * Run `enter` the first time `element` is on screen, and only then. Elements
 * already in view when the runtime refreshes fire on that refresh.
 *
 * The trigger sits at 97% of the viewport rather than the usual two thirds: the
 * pre-animation state of a counter (zero) and of a gauge (empty) are both
 * plausible-looking wrong readings, so they are only ever adopted for elements
 * that are within a hair of being visible.
 */
function onFirstView(ScrollTrigger, element, enter) {
    if (!ScrollTrigger) {
        enter()

        return
    }

    ScrollTrigger.create({ trigger: element, start: 'top 97%', once: true, onEnter: enter })
}

export function counters({ gsap, ScrollTrigger, MOTION }) {
    const boot = ++generation

    healInterrupted()

    // The first-appearance claim is written into the DOM, so it has to be given
    // back when this run is torn down. Without it a teardown that killed a
    // count mid-flight left the element marked as already-counted, and the next
    // run skipped it -- the value sat at whatever the interrupted tween had
    // reached, and the gauges never ran at all.
    gsap.context(() => () => {
        document.querySelectorAll(`[${CLAIMED}]`).forEach((element) => {
            element.removeAttribute(CLAIMED)
        })
    })

    /**
     * Count one text element up to the value it already shows.
     *
     * @param {HTMLElement} element
     * @param {number} delay
     * @param {number} duration
     */
    function rollUp(element, delay, duration) {
        // textContent is rewritten wholesale, so anything with child markup is
        // out of scope -- writing to it would delete the markup.
        if (element.children.length > 0 || element.hasAttribute(CLAIMED)) {
            return
        }

        // A first parse purely to decide whether this element is worth a trigger
        // at all. Its result is thrown away: the value that gets animated is
        // re-read below.
        if (!describe(element.textContent)) {
            return
        }

        element.setAttribute(CLAIMED, '')

        onFirstView(ScrollTrigger, element, () => {
            if (boot !== generation || !element.isConnected) {
                return
            }

            // Re-read at the moment of animating, never the value captured at
            // boot. A stat below the fold may have been re-polled several times
            // before it was scrolled to, and counting up to -- then writing back
            // -- the reading from page load would put stale data on screen.
            const original = element.textContent
            const format = describe(original)

            if (!format) {
                return
            }

            element.setAttribute(ORIGINAL, original)
            element.setAttribute(IN_FLIGHT, '')

            // Written before the tween starts so the delay is spent at zero
            // rather than at the real value -- otherwise the number visibly
            // drops to zero a beat after arriving.
            element.textContent = render(0, format)

            let settled = false

            const settle = () => {
                if (settled) {
                    return
                }

                settled = true

                if (element.isConnected) {
                    // The original string, not a re-render of it: this restores
                    // the surrounding whitespace exactly as Blade emitted it.
                    element.textContent = original
                    element.removeAttribute(IN_FLIGHT)
                    element.removeAttribute(ORIGINAL)
                }
            }

            const state = { value: 0 }

            gsap.to(state, {
                value: format.value,
                duration,
                delay,
                // power3.out rather than the panel's expo.out: expo puts 99% of
                // the movement in the first third, which on a counter reads as
                // the number snapping to its value and then sitting still.
                ease: MOTION.easeSoft,
                onUpdate() {
                    element.textContent = render(state.value, format)
                },
                onComplete: settle,
                onInterrupt: settle,
            })

            // The number is the only thing on screen that is briefly wrong, so
            // its correctness cannot depend on GSAP finishing. This runs on the
            // wall clock regardless, and no-ops if the tween already settled.
            window.setTimeout(settle, (delay + duration) * 1000 + 400)

            // The digits resolving: a short focus-pull that lands with the last
            // of the count. transformOrigin goes in the from-vars, not the
            // to-vars, so it is set once instead of being tweened -- an origin
            // that slides while the element scales makes the text drift.
            gsap.set(element, { willChange: 'transform, filter' })

            gsap.fromTo(
                element,
                { scale: 0.965, filter: 'blur(5px)', transformOrigin: 'left center' },
                {
                    scale: 1,
                    filter: 'blur(0px)',
                    duration: duration * 0.75,
                    delay,
                    ease: MOTION.ease,
                    clearProps: 'filter,transform,transformOrigin,willChange',
                },
            )
        })
    }

    /* --- Stat cards ---------------------------------------------------------
       The headline readings. Staggered across each widget so a row of six reads
       as one instrument sweeping rather than six unrelated numbers. */

    gsap.utils.toArray('.fi-wi-stats-overview').forEach((widget) => {
        gsap.utils
            .toArray(widget.querySelectorAll('.fi-wi-stats-overview-stat-value'))
            .forEach((value, index) => {
                // Capped: past half a dozen cards the tail of the stagger is
                // just latency before a number becomes readable.
                rollUp(value, Math.min(index, 6) * MOTION.stagger, MOTION.slow)
            })
    })

    /* --- Opt-in counters ----------------------------------------------------
       Any Blade can join in by adding `vf-count` to an element whose entire text
       is one formatted number. Same parser, same refusal rules. */

    gsap.utils.toArray('.vf-count').forEach((element, index) => {
        rollUp(element, Math.min(index, 8) * MOTION.stagger, MOTION.base)
    })

    /* --- Badges -------------------------------------------------------------
       Badges are overwhelmingly status words, and the numeric ones that do exist
       are counts. Only a bare grouped integer of two or more qualifies -- no
       prefix, no suffix, nothing to misread -- and never inside a table, whose
       rows are rebuilt on every poll. */

    gsap.utils.toArray('.fi-badge-label').forEach((label, index) => {
        if (label.closest('.fi-ta-table')) {
            return
        }

        const text = label.textContent.trim()

        if (!/^\d{1,3}(?:,\d{3})*$/.test(text) || Number(text.replace(/,/g, '')) < 2) {
            return
        }

        rollUp(label, Math.min(index, 8) * MOTION.stagger, MOTION.base)
    })

    /* --- Gauges -------------------------------------------------------------
       `.vf-gauge-fill` bars carry their true value as an inline width, so the
       sweep is scaleX from a left origin and the width stays the single source
       of truth -- off the layout path, and impossible for the animation to
       misreport.

       motion.css has its own keyframe version of this. It is neutralised rather
       than fought: an inline `animation: none` beats the stylesheet, so whether
       or not that rule is still there, exactly one sweep happens. Setting it
       first also means the failure mode of everything below is a fully drawn,
       correct bar.

       No overshoot easing here, unlike the rest of the panel's spring work: a
       gauge that bounces past its mark is a gauge briefly reporting a number the
       server did not send. */

    gsap.utils.toArray('.vf-gauge-fill').forEach((fill, index) => {
        if (fill.hasAttribute(CLAIMED)) {
            return
        }

        fill.setAttribute(CLAIMED, '')
        gsap.set(fill, { animation: 'none', transformOrigin: 'left center' })

        onFirstView(ScrollTrigger, fill, () => {
            if (boot !== generation || !fill.isConnected) {
                return
            }

            gsap.set(fill, { willChange: 'transform' })

            gsap.fromTo(
                fill,
                { scaleX: 0 },
                {
                    scaleX: 1,
                    duration: 0.9,
                    delay: Math.min(index, 10) * MOTION.stagger,
                    ease: MOTION.ease,
                    clearProps: 'willChange',
                },
            )
        })
    })

    /* --- Chart draw-in ------------------------------------------------------
       The bandwidth chart paints into a canvas owned by Chart.js inside a
       `wire:ignore` block, so nothing about the chart itself is touched: the
       canvas element is wiped in with clip-path and the clip is cleared the
       moment it finishes. Chart.js draws its tooltips into the same canvas and
       hit-tests against it, and clip-path clips hit-testing too -- hence the
       clearProps rather than leaving `inset(0 0 0 0)` behind.

       This is a reveal of the finished drawing, not a real line trace. Tracing
       the path would mean reaching inside the Chart instance, which Filament
       creates in an Alpine component loaded on demand and does not expose. */

    gsap.utils
        .toArray('.fi-wi-chart canvas, .fi-wi-stats-overview-stat-chart canvas')
        .forEach((canvas) => {
            if (canvas.hasAttribute(CLAIMED)) {
                return
            }

            // charts.js reaches the real Chart.js instance and draws the series
            // properly. Where it has taken a canvas, this generic clip wipe is
            // both redundant and harmful: two triggers on the same node, firing
            // at different scroll offsets, each clipping and unclipping it. The
            // specialist wins; this stays as the fallback for any chart it could
            // not adopt.
            if (canvas.hasAttribute('data-vf-chart')) {
                return
            }

            canvas.setAttribute(CLAIMED, '')

            const wipe = () => {
                if (boot !== generation || !canvas.isConnected) {
                    return
                }

                // A chart below the fold keeps its ordinary appearance. The wipe
                // is an arrival flourish and is not worth a code path that could
                // leave a clipped canvas behind on a trigger that never fires.
                const box = canvas.getBoundingClientRect()

                if (box.width === 0 || box.bottom < 0 || box.top > window.innerHeight) {
                    return
                }

                gsap.set(canvas, { willChange: 'clip-path' })

                gsap.fromTo(
                    canvas,
                    { clipPath: 'inset(0% 100% 0% 0%)' },
                    {
                        clipPath: 'inset(0% 0% 0% 0%)',
                        duration: MOTION.slow,
                        ease: MOTION.ease,
                        clearProps: 'clipPath,willChange',
                    },
                )
            }

            // The chart widget is lazy: Alpine fetches the chart component, then
            // Chart.js sizes and draws. Wiping before that reveals an empty
            // canvas and the chart then appears with no animation at all.
            //
            // There is no event to wait on -- Filament's Alpine component keeps
            // the Chart instance private -- so this watches for the one public
            // side effect of initialisation: Chart.js replaces the canvas's
            // 300x150 default backing store with the container size times the
            // device pixel ratio. Capped, and the cap still runs the wipe: on a
            // canvas that never drew it is invisible and harmless.
            const startedAt = performance.now()

            const waitForPaint = () => {
                if (boot !== generation || !canvas.isConnected) {
                    return
                }

                const drawn = canvas.width !== 300 || canvas.height !== 150

                if (drawn || performance.now() - startedAt > 2500) {
                    onFirstView(ScrollTrigger, canvas, wipe)

                    return
                }

                requestAnimationFrame(waitForPaint)
            }

            requestAnimationFrame(waitForPaint)
        })

    return null
}
