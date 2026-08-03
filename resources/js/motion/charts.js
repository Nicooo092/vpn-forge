/**
 * Chart choreography.
 * -------------------
 * The bandwidth chart is the one thing on this dashboard that is a *picture* of
 * the system rather than a number, so it is the one thing worth drawing rather
 * than revealing. counters.js already wipes the canvas open with a clip-path;
 * this module works underneath that, inside the Chart.js instance itself, so
 * what the wipe uncovers is a chart in the act of being drawn:
 *
 *   - the two series rise out of the zero baseline in a wave that travels left
 *     to right, download leading upload,
 *   - the legend, the grid and the axis labels fade up from a few pixels below
 *     on their own, shorter curve. They are drawn in the seam between Chart.js's
 *     `beforeDraw` and `beforeDatasetsDraw` hooks, which is what makes it
 *     possible to animate the chrome separately from the data without
 *     reimplementing either of them,
 *   - hovering lights the reading under the pointer: a guide line down the plot
 *     and a lit halo on each series' point,
 *   - changing the window (24h / 7d / 30d) dips the canvas, lets the y axis
 *     relabel itself out of sight, and re-draws the new series with the same
 *     rise instead of hard-swapping one picture for another,
 *   - clicking a legend entry fades that series out instead of deleting it.
 *
 * Reaching the instance
 * ---------------------
 * Filament renders the chart inside an Alpine component (`x-data="chart({...})"`
 * behind `wire:ignore`) that keeps the Chart.js instance private, and chart.js
 * is bundled inside Filament's own lazily-fetched asset, so there is no
 * `window.Chart` in this panel to look it up with. The one public door is
 * Alpine: `Alpine.$data(el)` returns the component's data object, which exposes
 * `getChart()`. Every step of that is guarded and every effect that depends on
 * it degrades to nothing -- an unreachable chart renders exactly as Filament
 * renders it today, with working tooltips and no animation.
 *
 * Rules this file holds itself to
 * -------------------------------
 *   - The chart is never left dimmed or mid-animation. Every path that dims the
 *     canvas has a wall-clock timer that restores it inside a second, and the
 *     Chart.js config is put back to Filament's instant one by a timer too --
 *     never by an animation callback that might not fire.
 *   - Chart.js's own 60s polling update and window resizes stay instant. Both
 *     arrive as `update('resize')` and are indistinguishable from the inside, so
 *     that mode is only ever armed for the couple of seconds after an operator
 *     has actually asked for a different window.
 *   - Hit-testing is never clipped, scaled or transformed. The canvas only ever
 *     receives opacity and blur, and both are cleared the moment they land --
 *     Chart.js maps pointer coordinates through the canvas's own box, and a
 *     scale on it would put every tooltip in the wrong place.
 */

import { CustomEase } from 'gsap/CustomEase'

/* GSAP's mirror of Chart.js's `easeOutQuart`, written as its cubic-bezier
   control points. The canvas and the pixels Chart.js paints into it move on the
   same curve during a window change, which is what makes the dip and the
   re-draw read as one gesture rather than two effects that happen to overlap. */
const EASE_NAME = 'vfChartQuart'
const EASE_PATH = 'M0,0 C0.165,0.84 0.44,1 1,1'

/* Chart.js identifies plugins by id, which is also how a chart that survived a
   re-boot avoids being given ours twice. */
const PLUGIN_ID = 'vfChartMotion'

const TAU = Math.PI * 2

/* Chart.js counts in milliseconds and GSAP in seconds, so the two halves of the
   vocabulary cannot literally share MOTION's numbers. These are MOTION's
   numbers written out in the other unit: DRAW tracks MOTION.slow, MORPH and
   CHROME track MOTION.base. */
const DRAW_MS = 820 /* one point's rise out of the baseline */
const SWEEP_MS = 520 /* how long the wave takes to cross the whole series */
const SERIES_MS = 150 /* download leads, upload follows */
const MORPH_MS = 620 /* the same rise, replayed for a new window */
const CHROME_MS = 460 /* legend, grid and axis labels arriving */
const CHROME_RISE = 8 /* px the chrome travels while it fades up */

/* The canvas is dimmed while the server fetches a different window. This is the
   hard ceiling on that: whatever the request does, the chart is legible again
   within a second. The dip is a transition, never a loading state. */
const DIP_HOLD_MS = 900

/* How long the re-draw stays armed after a window change. Long enough for a
   round trip on a slow link, short enough that a window resize a few seconds
   later is still instant. */
const MORPH_ARM_MS = 2600

/* Polling for new data to land. There is no event for it -- Filament's Alpine
   component owns the Livewire listener -- so the chart's own labels are watched
   instead, which is the thing that actually changes. */
const DATA_POLL_MS = 90

/* Waiting for the lazily-loaded Alpine component to build the chart. Generous,
   because it is a network fetch on a cold cache, and harmless when it expires:
   nothing happens and the chart stays exactly as Filament drew it. */
const CHART_WAIT_MS = 5000

/*
 * Every deferred callback in here captures the boot it belongs to.
 * `livewire:navigated` and a reduced-motion toggle both re-run the module, and a
 * timer from the previous run writing to a chart the current run owns would
 * fight it. Stale generations bail out.
 */
let generation = 0

/* CustomEase.create() registers a named ease globally; once is enough. */
let easeReady = false

/* Per-instance state, keyed by the Chart itself. An entry existing is also what
   tells the plugin below that this chart is ours -- a chart with no entry gets
   no guide line, no halo and no chrome fade, which is exactly what should
   happen to one the module never adopted or has since released. */
const owners = new WeakMap()

/* The adopted charts, so teardown can hand every one of them back. A WeakMap
   cannot be iterated; this is the list, and it is emptied on every run. */
let adopted = []

/**
 * Held at module scope so release() -- which runs from the teardown, outside the
 * exported function -- can still clear inline styles left by tweens that were
 * created after this module returned.
 */
let gsapRef = null

/* Listeners, timers and animation frames belonging to the current run. */
let scope = null

/* ------------------------------------------------------------------------- */
/* Lifecycle                                                                  */
/* ------------------------------------------------------------------------- */

/**
 * A bag of teardown callbacks. gsap.context() reverts tweens; it knows nothing
 * about listeners, timeouts or animation frames, so all of those go through here
 * and are unwound together.
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
        timer(callback, delay) {
            const id = window.setTimeout(callback, delay)
            disposers.push(() => window.clearTimeout(id))

            return id
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
                    console.warn('[vpn-forge motion] charts cleanup failed', error)
                }
            }
        },
    }
}

/**
 * Undo the previous run. Charts are handed back to Filament's own instant config
 * first: one left holding our animation options would animate its next poll,
 * which is the single update that must never move.
 */
function release() {
    adopted.forEach((chart) => {
        const own = owners.get(chart)

        if (own) {
            restoreConfig(own)
            owners.delete(chart)
        }
    })

    // Clear the dim residue by hand. dip() runs from a delegated change handler,
    // long after this module returned, so its tweens are created outside the
    // runtime's gsap.context() and a revert cannot reach them. If a teardown
    // landed inside the dip window -- reduced motion switched on mid-filter,
    // say -- the canvas would have stayed at 32% opacity behind a 5px blur with
    // nothing left running to bring it back.
    adopted.forEach((chart) => {
        const canvas = chart?.canvas

        if (canvas) {
            gsapRef?.set(canvas, { clearProps: 'opacity,filter,willChange' })
            canvas.removeAttribute('data-vf-chart')
        }
    })

    adopted = []

    if (scope) {
        scope.dispose()
        scope = null
    }
}

/* ------------------------------------------------------------------------- */
/* Reaching the Chart.js instance                                             */
/* ------------------------------------------------------------------------- */

/**
 * The Chart.js instance behind one `.fi-wi-chart-frame`, or null.
 *
 * `Alpine.$data(el)` is the documented way to read a component's data from
 * outside it. The `_x_dataStack` fallback is the same object by a private route,
 * kept because this is the single point of failure for everything below and a
 * second way in costs three lines.
 *
 * @param {?Element} frame
 * @returns {?object}
 */
function chartOf(frame) {
    if (!frame) {
        return null
    }

    let data = null

    const alpine = window.Alpine

    if (alpine && typeof alpine.$data === 'function') {
        try {
            data = alpine.$data(frame)
        } catch (error) {
            data = null
        }
    }

    if ((!data || typeof data.getChart !== 'function') && Array.isArray(frame._x_dataStack)) {
        data = frame._x_dataStack.find((layer) => layer && typeof layer.getChart === 'function')
    }

    if (!data || typeof data.getChart !== 'function') {
        return null
    }

    try {
        const chart = data.getChart()

        // chartArea only exists once the chart has laid itself out, which is the
        // signal that it is safe to measure and to animate.
        return chart && chart.ctx && chart.chartArea ? chart : null
    } catch (error) {
        return null
    }
}

/**
 * The raw options object Filament handed to the Chart constructor. Everything
 * here writes to that rather than to `chart.options`, which is a resolver proxy:
 * the raw object is the first scope that proxy reads from, and `chart.update()`
 * clears the resolver cache before re-reading it, so a plain assignment here is
 * both simpler and exactly as authoritative.
 *
 * @param {object} chart
 * @returns {?object}
 */
function optionsOf(chart) {
    const raw = chart && chart.config ? chart.config.options : null

    return raw && typeof raw === 'object' ? raw : null
}

function alive(chart) {
    return Boolean(chart && chart.ctx && chart.canvas && chart.canvas.isConnected)
}

/**
 * The pixel row of value zero on the y axis -- where a series rises from.
 *
 * Returns undefined rather than a guess when the scale cannot answer: Chart.js
 * reads a missing `from` as "start where you already are", so the failure mode
 * of this function is no animation, never a series flying in from the wrong edge.
 *
 * @param {object} chart
 * @returns {number|undefined}
 */
function baselineOf(chart) {
    const scale = chart && chart.scales ? chart.scales.y : null

    if (scale && typeof scale.getPixelForValue === 'function') {
        const pixel = scale.getPixelForValue(0)

        if (Number.isFinite(pixel)) {
            return pixel
        }
    }

    const area = chart && chart.chartArea

    return area && Number.isFinite(area.bottom) ? area.bottom : undefined
}

/**
 * A Chart.js `animations` block that lifts every point out of the baseline in a
 * wave crossing the series left to right, repeating a beat later for the next
 * dataset.
 *
 * Both `from` and `delay` are scriptable: Chart.js resolves them once per element
 * against a context carrying `chart`, `dataIndex` and `datasetIndex`, which is
 * the only way to give the points of a single dataset a stagger. Reading the
 * length off that context rather than closing over it also means the wave still
 * crosses the series in SWEEP_MS when a window change swaps 24 points for 30.
 *
 * @param {number} fallbackPoints  used only if the context cannot be read
 * @param {number} duration
 * @returns {object}
 */
function riseFromBaseline(fallbackPoints, duration) {
    const stepFor = (chart) => {
        const labels = chart && chart.data && Array.isArray(chart.data.labels) ? chart.data.labels : null
        const total = labels ? labels.length : fallbackPoints

        return total > 1 ? SWEEP_MS / (total - 1) : 0
    }

    return {
        y: {
            type: 'number',
            duration,
            easing: 'easeOutQuart',
            from: (context) => baselineOf(context && context.chart),
            delay: (context) =>
                context && context.type === 'data'
                    ? context.dataIndex * stepFor(context.chart) +
                      context.datasetIndex * SERIES_MS
                    : 0,
        },
    }
}

/* ------------------------------------------------------------------------- */
/* The Chart.js plugin                                                        */
/* ------------------------------------------------------------------------- */

/**
 * How far the chrome fade has got, on the same easeOutQuart curve as the series.
 * Self-clearing: once it lands it switches itself off, so the hooks below cost
 * one WeakMap lookup and one comparison for the rest of the page's life.
 *
 * @param {object} own
 * @returns {number}
 */
function chromeProgress(own) {
    if (!own.chromeStart) {
        return 1
    }

    const elapsed = (performance.now() - own.chromeStart) / CHROME_MS

    if (elapsed >= 1) {
        own.chromeStart = 0

        return 1
    }

    return elapsed <= 0 ? 0 : 1 - Math.pow(1 - elapsed, 4)
}

function unwind(chart, own) {
    if (!own.saved) {
        return
    }

    own.saved = false

    try {
        chart.ctx.restore()
    } catch (error) {
        // A restore with nothing to restore is not worth a broken frame.
    }
}

function activeOf(chart) {
    if (typeof chart.getActiveElements !== 'function') {
        return []
    }

    try {
        const active = chart.getActiveElements()

        return Array.isArray(active) ? active : []
    } catch (error) {
        return []
    }
}

/**
 * A hairline down the plot at the reading under the pointer. Drawn before the
 * series so the lines stay on top of it, and in the chart's own text colour --
 * which Filament resolves from a themed probe element -- so it follows the panel
 * between light and dark without a palette of its own.
 */
function drawGuide(chart, own) {
    if (!own.guides) {
        return
    }

    const active = activeOf(chart)
    const area = chart.chartArea

    if (!active.length || !area) {
        return
    }

    const element = active[0] && active[0].element

    if (!element || !Number.isFinite(element.x)) {
        return
    }

    const tint = chart.options ? chart.options.color : null

    if (typeof tint !== 'string' || tint === '') {
        return
    }

    // Half-pixel offset so a 1px line lands on one device pixel instead of
    // straddling two and rendering as a 2px smear.
    const x = Math.round(element.x) + 0.5
    const ctx = chart.ctx

    ctx.save()
    ctx.globalAlpha = 0.32
    ctx.strokeStyle = tint
    ctx.lineWidth = 1
    ctx.beginPath()
    ctx.moveTo(x, area.top)
    ctx.lineTo(x, area.bottom)
    ctx.stroke()
    ctx.restore()
}

/**
 * The active point of each series, lit. Two passes -- a wide soft disc for the
 * bloom, a small bright one with a shadow for the point itself -- both in that
 * series' own colour, so download and upload stay told apart.
 */
function drawHalo(chart, own) {
    if (!own.guides) {
        return
    }

    const active = activeOf(chart)

    if (!active.length) {
        return
    }

    const ctx = chart.ctx

    ctx.save()

    active.forEach((item) => {
        const element = item && item.element

        if (!element || !Number.isFinite(element.x) || !Number.isFinite(element.y)) {
            return
        }

        const options = element.options || {}
        const tint = firstColor(options.borderColor, options.backgroundColor)

        if (!tint) {
            return
        }

        const radius = Math.max(3, Number(options.hoverRadius) || 4)

        ctx.fillStyle = tint
        ctx.shadowBlur = 0
        ctx.globalAlpha = 0.16
        ctx.beginPath()
        ctx.arc(element.x, element.y, radius * 2.4, 0, TAU)
        ctx.fill()

        ctx.globalAlpha = 0.95
        ctx.shadowColor = tint
        ctx.shadowBlur = 14
        ctx.beginPath()
        ctx.arc(element.x, element.y, radius * 0.6, 0, TAU)
        ctx.fill()
    })

    ctx.restore()
}

/** A dataset colour can be a gradient or a pattern; only a plain CSS string is
 *  something the halo can safely reuse. */
function firstColor(...candidates) {
    for (const candidate of candidates) {
        if (typeof candidate === 'string' && candidate !== '') {
            return candidate
        }
    }

    return null
}

/**
 * Chart.js draws in this order:
 *
 *     beforeDraw -> boxes with z <= 0 (scales, grid, legend)
 *                -> beforeDatasetsDraw -> the series -> afterDatasetsDraw
 *                -> boxes with z > 0 -> afterDraw (tooltip)
 *
 * so everything between `beforeDraw` and `beforeDatasetsDraw` is the chart's
 * chrome and nothing else. Setting a canvas state there and restoring it at the
 * seam animates the legend and the axes on their own, without touching how
 * either is drawn.
 *
 * The save is tracked on the state object rather than trusted to pair up,
 * because `beforeDraw` is cancelable: a plugin returning false after ours would
 * skip the rest of the frame, and an unbalanced save() leaks a half-faded canvas
 * into the next one.
 */
const PLUGIN = {
    id: PLUGIN_ID,

    beforeDraw(chart) {
        const own = owners.get(chart)

        if (!own) {
            return
        }

        unwind(chart, own)

        const progress = chromeProgress(own)

        if (progress >= 1) {
            return
        }

        const ctx = chart.ctx

        ctx.save()
        own.saved = true
        ctx.globalAlpha = progress
        ctx.translate(0, (1 - progress) * CHROME_RISE)
    },

    beforeDatasetsDraw(chart) {
        const own = owners.get(chart)

        if (!own) {
            return
        }

        unwind(chart, own)
        drawGuide(chart, own)
    },

    afterDatasetsDraw(chart) {
        const own = owners.get(chart)

        if (own) {
            drawHalo(chart, own)
        }
    },

    // Belt and braces: if the frame ended early the seam never came, so this is
    // the last chance to leave the canvas state as we found it.
    afterDraw(chart) {
        const own = owners.get(chart)

        if (own) {
            unwind(chart, own)
        }
    },
}

/* ------------------------------------------------------------------------- */
/* Config handling                                                            */
/* ------------------------------------------------------------------------- */

/**
 * Remember Filament's own animation config before anything is changed, and note
 * whether each key was there at all -- putting a key back that never existed,
 * with the value undefined, leaves an own property behind that shadows
 * Chart.js's defaults.
 */
function snapshot(own, raw) {
    own.raw = raw
    own.hadAnimation = Object.prototype.hasOwnProperty.call(raw, 'animation')
    own.hadAnimations = Object.prototype.hasOwnProperty.call(raw, 'animations')
    own.hadTransitions = Object.prototype.hasOwnProperty.call(raw, 'transitions')
    own.baseAnimation = raw.animation
    own.baseAnimations = raw.animations
    own.baseTransitions = raw.transitions
}

function restoreKey(raw, key, had, value) {
    if (had) {
        raw[key] = value
    } else {
        delete raw[key]
    }
}

/** Hand the chart back exactly the config Filament gave it. */
function restoreConfig(own) {
    const raw = own.raw

    if (!raw) {
        return
    }

    restoreKey(raw, 'animation', own.hadAnimation, own.baseAnimation)
    restoreKey(raw, 'animations', own.hadAnimations, own.baseAnimations)
    restoreKey(raw, 'transitions', own.hadTransitions, own.baseTransitions)

    own.chromeStart = 0
}

/**
 * Back to instant for everything that is not the operator's doing. The legend
 * transitions stay, because a legend click is never something that polls.
 */
function goInstant(own) {
    const raw = own.raw

    if (!raw) {
        return
    }

    restoreKey(raw, 'animation', own.hadAnimation, own.baseAnimation)
    restoreKey(raw, 'animations', own.hadAnimations, own.baseAnimations)

    if (own.transitionsArmed) {
        raw.transitions = own.restTransitions
        own.transitionsArmed = false
    }
}

/**
 * Legend clicks are the one Chart.js update that is unambiguously the operator's
 * doing -- nothing polls it, nothing resizes it -- so `show` and `hide` are the
 * only transitions safe to leave armed for the life of the page. A series
 * dismissed from the legend fades out instead of blinking out of existence.
 */
function armLegendTransitions(own) {
    const raw = own.raw

    if (!raw) {
        return
    }

    const current = raw.transitions && typeof raw.transitions === 'object' ? raw.transitions : {}

    raw.transitions = Object.assign({}, current, {
        show: Object.assign({}, current.show, {
            animation: { duration: 420, easing: 'easeOutQuart' },
        }),
        hide: Object.assign({}, current.hide, {
            animation: { duration: 300, easing: 'easeOutQuart' },
        }),
    })

    // The resting state everything else returns to, so a window change does not
    // quietly undo this on its way out.
    own.restTransitions = raw.transitions
}

/* ------------------------------------------------------------------------- */
/* Module                                                                     */
/* ------------------------------------------------------------------------- */

/**
 * @param {{gsap: import('gsap').gsap, ScrollTrigger: object, MOTION: object}} api
 */
export function charts({ gsap, ScrollTrigger, MOTION }) {
    const boot = ++generation

    gsapRef = gsap

    // Undo the previous run before building anything: charts handed back, timers
    // cleared, listeners dropped.
    release()

    if (typeof document === 'undefined' || !document.body) {
        return null
    }

    // The empty-state fallback carries `.fi-wi-chart-frame` too, so the canvas is
    // what actually decides whether there is a chart here.
    const frames = gsap.utils
        .toArray('.fi-wi-chart .fi-wi-chart-frame')
        .filter((frame) => frame.querySelector('canvas'))

    if (!frames.length) {
        return null
    }

    if (!easeReady) {
        gsap.registerPlugin(CustomEase)
        CustomEase.create(EASE_NAME, EASE_PATH)
        easeReady = true
    }

    scope = createScope()

    // gsap.context() reverts tweens, not listeners or timers. This nested context
    // exists only so the runtime's revert() reaches release().
    gsap.context(() => () => release())

    const viewportHeight = () => window.innerHeight || document.documentElement.clientHeight || 0

    /* ------------------------------------------------------------------ *
     * The card's own chrome
     *
     * entrance.js partitions the dashboard by top-level block and hands a grid
     * containing stat cards to the cards themselves, so the chart widget beside
     * them gets nothing; scroll.js only prepares what starts BELOW the fold.
     * The gap between the two is a chart that is on screen at load, and that is
     * the only case this touches -- the fold test below is deliberately the
     * complement of scroll.js's, so a widget is never claimed by both.
     * ------------------------------------------------------------------ */
    function furnish(widget) {
        const box = widget.getBoundingClientRect()

        if (!box.height || box.top > viewportHeight() * 0.92 || box.bottom < 0) {
            return
        }

        const parts = []
        const text = widget.querySelector('.fi-section-header-text-ctn')
        const filter = widget.querySelector('.fi-wi-chart-filter')

        if (text) {
            parts.push(text)
        }

        if (filter) {
            parts.push(filter)
        }

        if (!parts.length) {
            return
        }

        gsap.from(parts, {
            y: 10,
            opacity: 0,
            duration: MOTION.base,
            ease: MOTION.ease,
            stagger: MOTION.stagger * 2,
            // Landing after the stat cards above have settled, so the dashboard
            // reads top to bottom rather than all at once.
            delay: 0.24,
            // A transform left on the header would make it the containing block
            // for anything fixed that ever opens out of it.
            clearProps: 'transform,opacity,willChange',
        })

        // The heading labels a live reading, so its visibility cannot depend on
        // a tween completing. This runs on the wall clock and costs nothing when
        // the tween already landed.
        scope.timer(() => {
            parts.forEach((part) => {
                if (part.isConnected && Number(gsap.getProperty(part, 'opacity')) < 0.99) {
                    gsap.set(part, { clearProps: 'transform,opacity,willChange' })
                }
            })
        }, 1600)
    }

    /* ------------------------------------------------------------------ *
     * Adoption
     * ------------------------------------------------------------------ */

    /**
     * Watch for the lazily-loaded Alpine component to finish building the chart,
     * then hand it over. Bounded, and its expiry is silent: no chart, no motion.
     */
    function whenReady(frame, handOver) {
        const startedAt = performance.now()
        let frameId = 0

        const tick = () => {
            frameId = 0

            if (boot !== generation || !frame.isConnected) {
                return
            }

            const chart = chartOf(frame)

            if (chart) {
                handOver(chart)

                return
            }

            if (performance.now() - startedAt > CHART_WAIT_MS) {
                return
            }

            frameId = requestAnimationFrame(tick)
        }

        frameId = requestAnimationFrame(tick)

        scope.add(() => {
            if (frameId) {
                cancelAnimationFrame(frameId)
            }
        })
    }

    /** One extra paint, so a fade that ran out of frames still lands on 1. */
    function repaint(chart, delay) {
        scope.timer(() => {
            if (boot !== generation || !alive(chart)) {
                return
            }

            try {
                chart.draw()
            } catch (error) {
                // A chart torn down between the timer and now is not an error.
            }
        }, delay)
    }

    function adopt(chart) {
        if (owners.has(chart)) {
            return
        }

        const raw = optionsOf(chart)

        if (!raw) {
            return
        }

        const type = chart.config ? chart.config.type : null

        const own = {
            /* A guide line and a halo are cartesian ideas. Arc elements have an
               x and a y too, and would get a line drawn through the middle of
               the doughnut. */
            guides: type === 'line' || type === 'scatter',
            chromeStart: 0,
            saved: false,
            intro: false,
            transitionsArmed: false,
        }

        snapshot(own, raw)
        owners.set(chart, own)
        adopted.push(chart)

        // Tells counters.js to leave this canvas alone. It has a generic
        // clip-path reveal for charts it cannot reach; where this module has the
        // real Chart instance, two reveals on one node would fight.
        chart.canvas?.setAttribute('data-vf-chart', '')

        armLegendTransitions(own)

        // Chart.js caches its plugin descriptors, so a plugin pushed after
        // construction is not seen until something invalidates them. update()
        // does, and 'none' is the mode that does it without animating or moving
        // a single pixel.
        const plugins = chart.config ? chart.config.plugins : null

        if (Array.isArray(plugins) && !plugins.some((plugin) => plugin && plugin.id === PLUGIN_ID)) {
            plugins.push(PLUGIN)
        }

        try {
            chart.update('none')
        } catch (error) {
            // A chart that cannot update is one we leave entirely alone.
            restoreConfig(own)
            owners.delete(chart)

            return
        }

        watchIntro(chart, own)
    }

    /* ------------------------------------------------------------------ *
     * The draw-in
     * ------------------------------------------------------------------ */

    function watchIntro(chart, own) {
        const canvas = chart.canvas

        if (!canvas) {
            return
        }

        const enter = () => {
            if (boot === generation) {
                play(chart, own)
            }
        }

        if (!ScrollTrigger) {
            enter()

            return
        }

        // 92% of the viewport rather than dead on the edge: the draw is worth
        // starting a hair before the chart is fully in frame, so the first thing
        // an operator sees of it is already in motion.
        ScrollTrigger.create({ trigger: canvas, start: 'top 92%', once: true, onEnter: enter })

        // If that trigger never fires -- a mis-measured refresh, a widget that
        // arrived after the last one -- a chart sitting in plain sight would
        // simply never draw. Cheap insurance, and a no-op once it has.
        scope.timer(() => {
            if (boot !== generation || own.intro || !alive(chart)) {
                return
            }

            const box = canvas.getBoundingClientRect()

            if (box.width > 0 && box.bottom > 0 && box.top < viewportHeight()) {
                play(chart, own)
            }
        }, 2600)
    }

    /**
     * The draw itself. Once per chart: this is the chart arriving, and an
     * arrival that replays is a chart that looks like it reloaded.
     */
    function play(chart, own) {
        if (own.intro || !alive(chart)) {
            return
        }

        own.intro = true

        const area = chart.chartArea
        const labels = chart.data && Array.isArray(chart.data.labels) ? chart.data.labels.length : 0

        // A chart in a collapsed section, or one with a single point, has nothing
        // to draw. Arming the chrome fade there would dim a legend nobody can see
        // until something happens to repaint it.
        if (!area || !(area.width > 0) || !(area.height > 0) || labels < 2) {
            return
        }

        const raw = own.raw

        raw.animations = riseFromBaseline(labels, DRAW_MS)
        raw.animation = Object.assign({}, own.baseAnimation, {
            duration: DRAW_MS,
            easing: 'easeOutQuart',
        })

        own.chromeStart = performance.now()

        try {
            chart.update()
        } catch (error) {
            goInstant(own)
            own.chromeStart = 0

            return
        }

        // Two guarantees on the wall clock, neither of which trusts the animator:
        // one last paint puts the chrome back at full opacity whatever happened
        // to the frames in between, and the config is instant again long before
        // the next 60s poll can land on it.
        repaint(chart, CHROME_MS + 140)
        repaint(chart, DRAW_MS + SWEEP_MS + SERIES_MS + 420)
        scope.timer(() => {
            if (boot === generation) {
                goInstant(own)
            }
        }, DRAW_MS + SWEEP_MS + SERIES_MS + 400)
    }

    /* ------------------------------------------------------------------ *
     * Changing the window
     *
     * A different filter is a different picture, not an update of this one, so
     * it gets drawn rather than morphed. The canvas dips while the request is
     * out -- which is also what covers the y axis relabelling itself, the one
     * part of a chart that genuinely cannot interpolate -- and the new series
     * rises out of the baseline exactly as it did on arrival.
     * ------------------------------------------------------------------ */

    function labelSignature(chart) {
        const labels = chart.data && Array.isArray(chart.data.labels) ? chart.data.labels : null

        return labels ? `${labels.length}:${labels[0]}:${labels[labels.length - 1]}` : ''
    }

    function dip(canvas) {
        gsap.set(canvas, { willChange: 'opacity, filter' })

        gsap.to(canvas, {
            opacity: 0.32,
            filter: 'blur(5px)',
            duration: MOTION.fast * 0.7,
            ease: MOTION.easeSoft,
            overwrite: 'auto',
        })
    }

    function undip(canvas) {
        gsap.to(canvas, {
            opacity: 1,
            filter: 'blur(0px)',
            duration: MOTION.base,
            ease: EASE_NAME,
            overwrite: 'auto',
            // No inline residue: Chart.js hit-tests against this element, and a
            // leftover filter would also make it the containing block for
            // anything fixed that ever renders inside the widget.
            clearProps: 'opacity,filter,willChange',
        })
    }

    function armMorph(own, points) {
        const current =
            own.raw.transitions && typeof own.raw.transitions === 'object' ? own.raw.transitions : {}

        // Filament's own handler updates in 'resize' mode, which Chart.js pins to
        // zero duration by default. Arming that mode is the only way in -- and
        // the reason it is disarmed again a couple of seconds later is that
        // genuine resizes and the 60s poll arrive through it too.
        own.raw.transitions = Object.assign({}, current, {
            resize: Object.assign({}, current.resize, {
                animation: { duration: MORPH_MS, easing: 'easeOutQuart' },
                animations: riseFromBaseline(points, MORPH_MS),
            }),
        })

        own.transitionsArmed = true
    }

    function onFilterChange(event) {
        const target = event.target

        if (!target || typeof target.closest !== 'function') {
            return
        }

        const wrapper = target.closest('.fi-wi-chart-filter')

        if (!wrapper) {
            return
        }

        const widget = wrapper.closest('.fi-wi-chart')
        const frame = widget ? widget.querySelector('.fi-wi-chart-frame') : null
        const canvas = frame ? frame.querySelector('canvas') : null

        if (!canvas) {
            return
        }

        // The control acknowledges the click before the server has answered.
        gsap.fromTo(
            wrapper,
            { scale: 0.965, transition: 'none' },
            {
                scale: 1,
                duration: MOTION.base,
                ease: MOTION.spring,
                overwrite: 'auto',
                clearProps: 'transform,transition',
            },
        )

        dip(canvas)

        let restored = false

        const restore = () => {
            if (restored) {
                return
            }

            restored = true

            if (canvas.isConnected) {
                undip(canvas)
            }
        }

        // The ceiling on the dip, held whatever the request does.
        scope.timer(restore, DIP_HOLD_MS)

        const chart = chartOf(frame)
        const own = chart ? owners.get(chart) : null

        if (!chart || !own || !own.raw) {
            return
        }

        const points = chart.data && Array.isArray(chart.data.labels) ? chart.data.labels.length : 0

        if (points > 1) {
            armMorph(own, points)
        }

        // Nothing announces the new data: Filament's Alpine component owns the
        // Livewire listener and keeps it to itself. The labels are what actually
        // change, so they are what gets watched -- bounded, and it stops the
        // moment it has an answer.
        const before = labelSignature(chart)
        const startedAt = performance.now()

        const watcher = window.setInterval(() => {
            const expired = performance.now() - startedAt > MORPH_ARM_MS
            const landed = alive(chart) && labelSignature(chart) !== before

            if (!landed && !expired && boot === generation) {
                return
            }

            window.clearInterval(watcher)

            if (boot !== generation) {
                return
            }

            restore()

            // Only when the re-draw is actually running: the chrome fade needs
            // the frames that animation produces, and without them the legend
            // would sit dimmed until the repaint below caught it.
            if (landed && own.transitionsArmed) {
                own.chromeStart = performance.now()
                repaint(chart, CHROME_MS + 140)
            }

            // Instant again well before the next poll, which must not animate.
            scope.timer(() => {
                if (boot === generation) {
                    goInstant(own)
                }
            }, MORPH_MS + SWEEP_MS + 400)
        }, DATA_POLL_MS)

        scope.add(() => window.clearInterval(watcher))
    }

    // Delegated: the section header is ordinary Livewire markup and is morphed in
    // place on every poll, so a listener bound to the select itself would be
    // dropped along with it.
    scope.on(document, 'change', onFilterChange, { passive: true })

    /* ------------------------------------------------------------------ *
     * Wiring
     * ------------------------------------------------------------------ */

    frames.forEach((frame) => {
        const widget = frame.closest('.fi-wi-chart')

        if (widget) {
            furnish(widget)
        }

        whenReady(frame, adopt)
    })

    return null
}
