/*
 * Performance governor
 * --------------------
 * Every other module in this system asks one question before it decides how
 * much work to do: how much can this machine actually afford? This module is
 * the answer. It runs first, measures the device honestly, publishes a verdict
 * on `window.__vfMotion`, acts on that verdict itself, and then keeps watching
 * -- because a verdict taken in the first second of a page is a guess about the
 * next hour of a session.
 *
 * THE CONTRACT (read defensively, it may not exist):
 *
 *   window.__vfMotion = {
 *       version: 1,
 *       tier:    'high' | 'medium' | 'low',   // reassigned live, never removed
 *       reduced: boolean,                     // live getter, not a snapshot
 *       signals: { ... },                     // what the verdict was based on
 *       allow(feature)  -> boolean,           // may I run this effect?
 *       atLeast(tier)   -> boolean,
 *       force(value)    -> boolean,           // operator escape hatch
 *       report()        -> signals,           // re-log the diagnosis
 *   }
 *
 *   document / window: 'vf:motion-tier' CustomEvent whenever the tier changes,
 *   with { tier, previous, reason } in detail. Continuous and ambient modules
 *   MUST listen for it and shut themselves down when it arrives saying 'low'.
 *
 *   <html> carries `vf-tier-<tier>` for CSS to key on, plus `vf-motion-low`
 *   when the panel is being asked to hold back.
 *
 * WHY A GUESS IS NOT ENOUGH. `hardwareConcurrency` is 2 on a genuine netbook
 * and also 2 on a privacy-hardened Firefox running on a 16-core workstation.
 * `deviceMemory` does not exist outside Chromium at all. A 4K panel driven by
 * an integrated GPU reports excellent numbers and drops half its frames. So the
 * static signals only set a starting position and a ceiling; real measured
 * frames decide the rest, and they are allowed to move the verdict in both
 * directions -- down freely, up exactly once, and never above the ceiling.
 *
 * WHY THE WATCHDOG IS DUTY-CYCLED. A permanent requestAnimationFrame loop is
 * the cheapest possible way to make a browser never idle: it pins the display
 * pipeline at full rate forever, on a module whose entire job is to save work.
 * So it samples for a second, sleeps for several, and backs that sleep off as
 * the session proves itself. A long-task observer wakes it early when the page
 * starts struggling, which is the only moment its answer matters. And once the
 * verdict reaches 'low' with nothing left to prove, it stops entirely -- the
 * weakest devices end up paying nothing at all for being measured.
 *
 * SAFETY. Nothing here hides, moves, fades or clips a single pixel of content.
 * The worst thing a bug in this file can do is pick the wrong tier.
 */

/* ------------------------------------------------------------------------- */
/* Contract                                                                   */
/* ------------------------------------------------------------------------- */

const GLOBAL = '__vfMotion'
const EVENT = 'vf:motion-tier'

/* Bumped if the shape of window.__vfMotion ever changes incompatibly, so a
   module can tell a contract it understands from one it does not. */
const CONTRACT = 1

const TIERS = ['low', 'medium', 'high']
const RANK = { low: 0, medium: 1, high: 2 }

/* An operator can pin or kill motion permanently from the console:
   `__vfMotion.force('off')`, or localStorage directly. */
const STORAGE_KEY = 'vf-motion'

const STYLE_ID = 'vf-perf'
const OWNED_ATTR = 'data-vf-perf'

/* The switch the CSS layer keys on. Only ever present at 'low'. */
const RESTRAINT_CLASS = 'vf-motion-low'

/**
 * Minimum tier at which a named effect is worth what it costs.
 *
 * The names are deliberately about the KIND of work, not about which module
 * does it -- two modules asking for a full-screen canvas should get the same
 * answer, and a module that gets renamed should not silently change tier.
 *
 * An unrecognised name resolves to DEFAULT_NEED rather than to false. A typo
 * that silently switches a module off is the same failure mode as an invented
 * CSS selector: dead code that nobody notices. Failing open at 'high' and
 * 'medium' and closed at 'low' is the behaviour a caller would have written by
 * hand anyway.
 */
const DEFAULT_NEED = 'medium'

const FEATURES = {
    /* Always affordable. These are the panel reporting state -- an arrival, a
       measurement, a value that changed. Switching them off does not save a
       weak device anything worth having, because they are one-shots that end. */
    entrance: 'low',
    reveal: 'low',
    counters: 'low',
    gauge: 'low',
    chart: 'low',
    stagger: 'low',
    flip: 'low',
    press: 'low',
    focus: 'low',
    nav: 'low',
    overlay: 'low',
    empty: 'low',

    /* Worth it on anything that is not already struggling. Mostly per-frame
       work bounded by a gesture or a scroll: it stops when the operator does. */
    parallax: 'medium',
    scrub: 'medium',
    tilt: 'medium',
    magnetic: 'medium',
    spotlight: 'medium',
    text: 'medium',
    shimmer: 'medium',
    blur: 'medium',
    shadow: 'medium',
    celebrate: 'medium',
    transition: 'medium',

    /* Only where there is headroom to spare. Everything here either never stops
       on its own, composites the whole viewport, or both. */
    ambient: 'high',
    depth: 'high',
    particles: 'high',
    confetti: 'high',
    cursor: 'high',
    trail: 'high',
    physics: 'high',
    canvas: 'high',
    backdrop: 'high',
    gradient: 'high',
    glow: 'high',
    noise: 'high',
}

/* ------------------------------------------------------------------------- */
/* Sampler tuning                                                             */
/* ------------------------------------------------------------------------- */

/* One measurement window. Long enough that a single garbage collection does not
   define it, short enough to react inside the "few seconds" the brief allows. */
const WINDOW_MS = 1000

/* A window built from fewer frames than this says nothing useful, so it is
   extended -- up to a hard stop, because a device rendering four frames per
   second has already answered the question. */
const MIN_FRAMES = 8
const WINDOW_MAX_MS = 4000

/* Rest between windows while everything is fine, growing as the session proves
   itself. A panel that has been smooth for five minutes does not need checking
   every five seconds. */
const IDLE_MS = 5000
const IDLE_MAX_MS = 40000
const IDLE_GROWTH = 1.4

/* While a provisional verdict still has a step to earn back, look more often --
   otherwise a fast machine that was guessed wrong is held down for a minute. */
const IDLE_EAGER_MS = 2000

/* A tab that has just become visible is busy catching up. Measuring that would
   measure the catch-up, not the panel. */
const SETTLE_MS = 600

/* A frame gap this long is the browser suspending rAF -- a hidden tab, a closed
   lid, a paused debugger -- not a frame the panel took too long to draw. */
const SUSPENDED_MS = 900

/* Above this, a frame is a stall rather than a slow frame. 50ms is roughly the
   point where a pointer gesture stops feeling attached to the cursor. */
const STALL_MS = 50

/* Long-task time that has to pile up before a sleeping sampler wakes early. */
const WAKE_STALL_MS = 250

/* Never demand more than 60fps of a machine, however fast its display is. */
const BUDGET_CAP = 60

/* A window is bad below this share of the display's own achievable rate... */
const BAD_SHARE = 0.55
/* ...or below this absolute rate, whatever the display claims. This is what
   catches a panel stuck at 25fps whose "fastest frame" estimate has sunk to
   match, and a browser that has throttled rAF for power saving. */
const BAD_FLOOR = 32

/* The first window overlaps the arrival sequence, Livewire booting and Chart.js
   parsing, so it is judged far more leniently: it is looking for a machine that
   cannot do this at all, not for a busy first second. */
const PROBE_SHARE = 0.45
const PROBE_FLOOR = 26

/* Excellence, for the one restoration a provisional verdict is allowed. */
const GOOD_SHARE = 0.94
const GOOD_FLOOR = 50
const GOOD_STALL_SHARE = 0.05

/* Time lost to stalls, as a share of the window, that makes it bad on its own.
   A window can average 50fps and still be unusable if a third of it went into
   two 300ms freezes. */
const STALL_SHARE = 0.35

/* Consecutive bad windows before the tier actually moves. One is a garbage
   collection; three in a row is a device that cannot keep up. */
const BAD_STREAK = 3

/* Consecutive excellent windows before a provisional verdict is restored. */
const GOOD_STREAK = 4

/* ------------------------------------------------------------------------- */
/* Module state                                                               */
/*                                                                            */
/* All of this survives the runtime's teardown/boot cycle on purpose. The       */
/* device does not change when an operator clicks a nav item, and a tier that   */
/* was downgraded after ten seconds of dropped frames must not quietly reset to */
/* 'high' on the next page.                                                     */
/* ------------------------------------------------------------------------- */

/** The published object. Created once, then mutated in place -- a module that
 *  captured a reference keeps seeing the truth. */
let api = null

/** Static verdict: what the device's own numbers alone suggest. */
let base = null

/** Facts the verdict was drawn from, refreshed on every run. */
let signals = null

/** The published tier. */
let tier = DEFAULT_NEED

/** Steps the frame-rate watchdog has moved the tier by, relative to `base`.
 *  Kept separately from the tier itself so re-reading the device on a later
 *  navigation cannot silently undo a measured downgrade. */
let shift = 0

/** Restorations still allowed. Only ever 1, and only for a verdict that was
 *  built on missing information in the first place. */
let upgrades = 0

let badRun = 0
let goodRun = 0
let idleFor = IDLE_MS

let logged = false

/** The current run's teardown bag, and the sampler it owns. */
let scope = null
let sampler = null

/** Held so `force()` can act without waiting for a navigation. Always the same
 *  singleton the runtime imports. */
let gsapRef = null

/** One MediaQueryList for the life of the document. `reduced` is exposed as a
 *  live getter rather than a snapshot, so the record stays truthful even after
 *  the runtime has stopped re-running us -- which is exactly what happens when
 *  an operator turns reduced motion ON mid-session. */
let reducedQuery = null

/* ------------------------------------------------------------------------- */
/* Small helpers                                                              */
/* ------------------------------------------------------------------------- */

function clamp(min, max, value) {
    return value < min ? min : value > max ? max : value
}

function matches(query) {
    try {
        return window.matchMedia(query).matches
    } catch (error) {
        return false
    }
}

function prefersReduced() {
    try {
        if (!reducedQuery) {
            reducedQuery = window.matchMedia('(prefers-reduced-motion: reduce)')
        }

        return reducedQuery.matches
    } catch (error) {
        return false
    }
}

function info(message) {
    try {
        console.info(`[vpn-forge motion] ${message}`)
    } catch (error) {
        // A console that refuses to log is not a reason to stop working.
    }
}

/**
 * A bag of teardown callbacks. Every listener, timer, frame request and
 * observer in this file goes through one, because gsap.context() reverts tweens
 * and nothing else.
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
                    console.warn('[vpn-forge motion] perf cleanup failed', error)
                }
            }
        },
    }
}

/* ------------------------------------------------------------------------- */
/* Measuring the device                                                       */
/* ------------------------------------------------------------------------- */

/**
 * Everything knowable without rendering a frame. Re-read on every run rather
 * than cached: a laptop gets docked to a 4K monitor, a window gets dragged to a
 * second screen with a different pixel ratio, and a connection switches to
 * metered -- all without a page load.
 */
function readSignals() {
    const nav = typeof navigator === 'object' && navigator ? navigator : {}
    const connection = nav.connection || nav.mozConnection || nav.webkitConnection || null

    const cores =
        typeof nav.hardwareConcurrency === 'number' && nav.hardwareConcurrency > 0
            ? nav.hardwareConcurrency
            : null

    /* Chromium only, capped at 8 by the spec, and rounded down to a power of two
       for fingerprinting reasons -- so it is a coarse hint, never a measurement. */
    const memory =
        typeof nav.deviceMemory === 'number' && nav.deviceMemory > 0 ? nav.deviceMemory : null

    const dpr =
        typeof window.devicePixelRatio === 'number' && window.devicePixelRatio > 0
            ? window.devicePixelRatio
            : 1

    const width = window.innerWidth || document.documentElement.clientWidth || 0
    const height = window.innerHeight || document.documentElement.clientHeight || 0

    return {
        contract: CONTRACT,
        cores,
        memory,
        dpr: Math.round(dpr * 100) / 100,
        /* Physical pixels the compositor has to fill. This, not the CSS size, is
           what a full-screen blur or an ambient canvas actually costs. */
        pixels: Math.round(width * height * dpr * dpr),
        coarse: matches('(pointer: coarse)'),
        saveData: Boolean(connection && connection.saveData),
        network:
            connection && typeof connection.effectiveType === 'string'
                ? connection.effectiveType
                : null,
        reduced: prefersReduced(),
        /* A page that boots in a background tab cannot be frame-rate tested at
           all -- rAF does not run. Recorded so the log explains the missing fps. */
        visible: !document.hidden,
        override: readOverride(),
        fps: null,
        budget: null,
        source: null,
    }
}

/**
 * The escape hatch. `off` stands the motion layer down, a tier name pins it
 * there, anything else means automatic.
 */
function readOverride() {
    try {
        const stored = window.localStorage.getItem(STORAGE_KEY)

        if (stored === 'off' || stored === 'low' || stored === 'medium' || stored === 'high') {
            return stored
        }
    } catch (error) {
        // Private mode, blocked storage, a sandboxed iframe. Not having an
        // override is the normal case anyway.
    }

    return null
}

/**
 * Turn the static signals into a starting tier and a ceiling.
 *
 * `hard` marks a verdict that measurement is not allowed to touch, because it
 * came from a statement rather than a guess: the operator pinned it, the
 * operating system asked for reduced motion, or the connection is metered.
 *
 * `ceiling` is one step above the verdict whenever the verdict leaned on
 * information the browser refused to give us. That is the only door through
 * which a machine can climb back out of a pessimistic guess.
 */
function judge(s) {
    if (s.override === 'off') {
        return { tier: 'low', ceiling: 'low', hard: true, off: true, source: 'forced off' }
    }

    if (s.override) {
        return {
            tier: s.override,
            ceiling: s.override,
            hard: true,
            off: false,
            source: 'pinned by operator',
        }
    }

    if (s.reduced) {
        return { tier: 'low', ceiling: 'low', hard: true, off: false, source: 'reduced motion' }
    }

    /* Save-Data is the operator telling the browser to spend less on their
       behalf. Honouring it for CPU as well as bytes is the obvious reading. */
    if (s.saveData) {
        return { tier: 'low', ceiling: 'low', hard: true, off: false, source: 'save-data' }
    }

    let score = 0
    let soft = false

    if (s.cores === null) {
        /* Safari and anything with fingerprint resistance. No information is not
           bad news -- but it is not good news either, so the verdict it produces
           stays open to being corrected by real frames. */
        soft = true
    } else if (s.cores >= 8) {
        score += 2
    } else if (s.cores >= 6) {
        score += 1
    } else if (s.cores >= 4) {
        score += 0
    } else if (s.cores === 3) {
        score -= 1
        soft = true
    } else {
        /* One or two cores: a real netbook, or a privacy-hardened Firefox on a
           workstation reporting 2 because it always reports 2. Both start low;
           only one of them will earn its way back up. */
        score -= 2
        soft = true
    }

    if (s.memory === null) {
        soft = true
    } else if (s.memory >= 8) {
        score += 2
    } else if (s.memory >= 4) {
        score += 1
    } else if (s.memory >= 2) {
        score -= 1
    } else {
        score -= 2
    }

    /* Pixels to composite. The classic bad case in this panel is a perfectly
       respectable laptop driving a 4K monitor from an integrated GPU: excellent
       specs, and a full-screen effect that costs four times what it should. */
    if (s.pixels > 4200000) {
        score -= 1
    }

    if (s.pixels > 8400000) {
        score -= 1
    }

    /* A coarse pointer is a phone or a tablet: a thermal budget rather than a
       power supply, and a browser that will throttle rather than get hot. Soft,
       because the fast ones are very fast. */
    if (s.coarse) {
        score -= 1
        soft = true
    }

    /* Not a CPU measurement -- but a machine on a 2G link is rarely a machine
       with frames to spare, and the panel it is loading will be fighting for the
       main thread for a while yet. */
    if (s.network === 'slow-2g' || s.network === '2g') {
        score -= 1
    }

    const decided = score >= 2 ? 'high' : score >= 0 ? 'medium' : 'low'
    const ceiling = soft ? TIERS[Math.min(RANK[decided] + 1, 2)] : decided

    return {
        tier: decided,
        ceiling,
        hard: false,
        off: false,
        source: soft ? 'hardware (incomplete)' : 'hardware',
    }
}

/* ------------------------------------------------------------------------- */
/* Acting on the verdict                                                      */
/* ------------------------------------------------------------------------- */

/*
 * Injected only at 'low', and scoped to the class this module puts on <html>.
 *
 * The brief for this file says the CSS layer can use that class to switch heavy
 * effects off; motion.css was written before the class existed, so these are the
 * rules that make the class mean something today. Every one of them targets a
 * selector motion.css itself owns (verified against it, and through it against
 * vendor/filament/**), and every one is either continuous -- it costs something
 * every frame for as long as it is on screen -- or a backdrop-filter, which is a
 * full-viewport blur pass and the single most expensive thing this panel does.
 *
 * Nothing here hides content. The loading placeholder keeps its card and its
 * label, the live badge sits at full opacity instead of breathing, the modal
 * keeps its scrim and only loses the blur behind it.
 */
const RESTRAINT_CSS = `
.${RESTRAINT_CLASS} .fi-modal > .fi-modal-close-overlay {
    -webkit-backdrop-filter: none;
    backdrop-filter: none;
}

.${RESTRAINT_CLASS} .fi-loading-section,
.${RESTRAINT_CLASS} .vf-skeleton,
.${RESTRAINT_CLASS} .fi-ta.fi-loading,
.${RESTRAINT_CLASS} .fi-ta-ctn.fi-loading,
.${RESTRAINT_CLASS} .vf-live {
    animation: none;
}

.${RESTRAINT_CLASS} .fi-loading-section::after,
.${RESTRAINT_CLASS} .vf-skeleton::after {
    display: none;
}
`

function removeInjected() {
    document
        .querySelectorAll(`#${STYLE_ID}, [${OWNED_ATTR}]`)
        .forEach((node) => node.remove())
}

function injectRestraint() {
    if (!document.head || document.getElementById(STYLE_ID)) {
        return
    }

    const style = document.createElement('style')
    style.id = STYLE_ID
    style.setAttribute(OWNED_ATTR, 'restraint')
    style.textContent = RESTRAINT_CSS
    document.head.appendChild(style)
}

/**
 * Everything the verdict actually does. Idempotent, because it runs on every
 * boot as well as on every live tier change.
 */
function applyTier(next) {
    const root = document.documentElement

    if (root && root.classList) {
        root.classList.toggle('vf-tier-high', next === 'high')
        root.classList.toggle('vf-tier-medium', next === 'medium')
        root.classList.toggle('vf-tier-low', next === 'low')
        root.classList.toggle(RESTRAINT_CLASS, next === 'low')
    }

    if (next === 'low') {
        injectRestraint()
    } else {
        removeInjected()
    }

    const ticker = gsapRef && gsapRef.ticker

    if (!ticker) {
        return
    }

    if (next === 'low') {
        /* Half rate rather than two thirds. 30 divides evenly into both 60Hz and
           120Hz, so every GSAP frame lands on a real display frame; a cap of 45
           on a 60Hz panel drops one frame in four at uneven intervals, which
           reads worse than the half rate it was meant to avoid. */
        ticker.fps(30)

        /* A device this slow stalls for hundreds of milliseconds at a time.
           Tightening the lag threshold makes GSAP treat those as dropped time
           and clamp the delta, instead of jumping every running tween a third of
           a second forward the moment the main thread comes back. */
        ticker.lagSmoothing(300, 33)
    } else {
        // GSAP's own defaults: no throttling, 500ms before a gap counts as lag.
        ticker.fps(240)
        ticker.lagSmoothing(500, 33)
    }
}

function announce(previous, reason) {
    if (api) {
        api.tier = tier
    }

    info(`tier ${previous} -> ${tier} (${reason})`)

    try {
        /* Dispatched on `document` and allowed to bubble, so a listener on either
           `document` or `window` sees it -- window is in the propagation path of
           a document event, and a module author will reasonably reach for either
           one. */
        document.dispatchEvent(
            new CustomEvent(EVENT, {
                bubbles: true,
                detail: { tier, previous, reason, signals },
            }),
        )
    } catch (error) {
        // Nothing in this module depends on the event being delivered.
    }
}

/**
 * Recompute the published tier from the static verdict plus whatever the
 * watchdog has since learned, and put it into effect.
 *
 * @param {string} reason  why this happened, for the log and the event
 * @param {boolean} quiet  true during the very first boot, when there is no
 *                         previous tier to have changed from
 */
function settle(reason, quiet) {
    const previous = tier

    base = judge(signals)

    tier = base.hard ? base.tier : TIERS[clamp(0, 2, RANK[base.tier] + shift)]

    if (base.hard) {
        // A pinned or forced verdict is not up for negotiation, and the watchdog
        // has nothing left to decide.
        shift = 0
        upgrades = 0
    }

    signals.source = base.source

    applyTier(tier)

    if (!quiet && tier !== previous) {
        announce(previous, reason)
    }
}

/* ------------------------------------------------------------------------- */
/* Published API                                                              */
/* ------------------------------------------------------------------------- */

/**
 * May a named effect run? Unknown names fail open above 'low' -- see
 * FEATURES.
 */
function allow(feature) {
    if (!base || base.off || prefersReduced()) {
        return false
    }

    const need = FEATURES[feature] ?? DEFAULT_NEED

    return RANK[tier] >= (RANK[need] ?? RANK[DEFAULT_NEED])
}

function atLeast(wanted) {
    if (!base || base.off || prefersReduced()) {
        return false
    }

    const need = RANK[wanted]

    return need === undefined ? allow() : RANK[tier] >= need
}

/**
 * The escape hatch, made usable from the console:
 *
 *   __vfMotion.force('off')     stand the motion layer down, permanently
 *   __vfMotion.force('low')     pin a tier (also how you test one)
 *   __vfMotion.force(null)      back to automatic
 *
 * Persisted, so it survives the reload it invites, and applied immediately so
 * the operator can see it worked. Note the limit of "off": it makes `allow()`
 * refuse everything and puts the panel at tier 'low', which is what every
 * module in this system is told to consult -- it cannot reach back into effects
 * that have already been built by a module that ignored the contract.
 */
function force(value) {
    const wanted =
        value === null || value === undefined || value === 'auto' ? null : String(value)

    if (wanted !== null && wanted !== 'off' && RANK[wanted] === undefined) {
        info(`force(): expected 'off', 'low', 'medium', 'high' or null -- got ${wanted}`)

        return false
    }

    try {
        if (wanted === null) {
            window.localStorage.removeItem(STORAGE_KEY)
        } else {
            window.localStorage.setItem(STORAGE_KEY, wanted)
        }
    } catch (error) {
        console.warn('[vpn-forge motion] the motion override could not be stored', error)

        return false
    }

    signals.override = readOverride()
    settle(wanted === null ? 'override cleared' : `override "${wanted}"`, false)

    // A pinned verdict has nothing to watch for; an automatic one does.
    if (sampler) {
        if (base.hard) {
            sampler.stop()
        } else {
            sampler.start()
        }
    }

    info(`motion override is now ${wanted === null ? 'automatic' : `"${wanted}"`}`)

    return true
}

/** Re-log the current diagnosis, and hand it back for inspection. */
function report() {
    info(describe())

    return signals
}

function describe() {
    const parts = [
        `tier "${tier}"`,
        signals.cores === null ? 'cores ?' : `${signals.cores} cores`,
        signals.memory === null ? 'memory ?' : `${signals.memory}GB`,
        `${signals.dpr}x dpi`,
        `${(signals.pixels / 1000000).toFixed(1)}MP`,
        signals.fps === null ? 'fps pending' : `${signals.fps}fps`,
        signals.source,
    ]

    if (signals.saveData) {
        parts.push('save-data')
    }

    if (signals.coarse) {
        parts.push('touch')
    }

    return parts.join(', ')
}

function publish() {
    if (api) {
        api.tier = tier
        api.signals = signals

        return
    }

    api = {
        version: CONTRACT,
        tier,
        signals,
        allow,
        atLeast,
        force,
        report,
    }

    /* A getter, not a snapshot. If an operator turns reduced motion on, the
       runtime stops re-running every module -- including this one -- so a stored
       boolean would stay wrong for the rest of the session. */
    Object.defineProperty(api, 'reduced', {
        enumerable: true,
        get: prefersReduced,
    })

    try {
        window[GLOBAL] = api
    } catch (error) {
        console.warn('[vpn-forge motion] could not publish the motion contract', error)
    }
}

/* ------------------------------------------------------------------------- */
/* Frame-rate sampler                                                         */
/* ------------------------------------------------------------------------- */

/**
 * Duty-cycled frame-rate sampler.
 *
 * Measures one window of real frames, hands the result to `onWindow`, and does
 * what it is told next: `0` to open another window immediately, a positive
 * number to sleep that long first, `-1` to stop for good.
 *
 * It stops dead while the tab is hidden -- partly because rAF does not run
 * there so there is nothing to measure, but mostly because measuring anyway
 * would report a frame rate of nearly zero and downgrade a perfectly good
 * machine for the crime of being in a background tab.
 *
 * @param {object} host   teardown bag
 * @param {(report: {index: number, fps: number, budget: number, stallShare: number}) => number} onWindow
 */
function createSampler(host, onWindow) {
    let running = false
    let frame = 0
    let timer = 0
    let index = 0

    /* Current window. */
    let anchored = false
    let start = 0
    let last = 0
    let frames = 0
    let stalled = 0

    /* Session-wide. The shortest frame ever observed is the best evidence
       available of what this display is actually capable of -- far better than
       assuming 60Hz, which would punish a 30Hz panel for being a 30Hz panel and
       let a 120Hz one coast. */
    let fastest = Infinity

    let longTaskMs = 0

    function clearPending() {
        if (frame) {
            cancelAnimationFrame(frame)
            frame = 0
        }

        if (timer) {
            clearTimeout(timer)
            timer = 0
        }
    }

    function open() {
        clearPending()

        if (!running || document.hidden) {
            return
        }

        anchored = false
        start = 0
        last = 0
        frames = 0
        stalled = 0
        longTaskMs = 0
        frame = requestAnimationFrame(tick)
    }

    function rest(ms) {
        clearPending()

        if (!running) {
            return
        }

        timer = setTimeout(open, ms)
    }

    function stop() {
        running = false
        clearPending()
    }

    function tick(stamp) {
        frame = 0

        if (!running || document.hidden) {
            return
        }

        if (!anchored) {
            /* The first callback only anchors the clock. The gap between opening
               the window and the next frame is scheduling latency, not a frame
               the panel was slow to draw. A separate flag rather than `start`
               being zero: the timestamp is a real number the browser chooses,
               and a sentinel it could theoretically produce is a sentinel that
               can hang this loop. */
            anchored = true
            start = stamp
            last = stamp
            frame = requestAnimationFrame(tick)

            return
        }

        const delta = stamp - last
        last = stamp

        if (delta > SUSPENDED_MS) {
            // Throttled, not slow. Throw the window away and start again.
            anchored = false
            frames = 0
            stalled = 0
            frame = requestAnimationFrame(tick)

            return
        }

        frames += 1

        if (delta > STALL_MS) {
            stalled += delta
        }

        if (delta > 0 && delta < fastest) {
            fastest = delta
        }

        const elapsed = stamp - start

        if (elapsed < WINDOW_MS || (frames < MIN_FRAMES && elapsed < WINDOW_MAX_MS)) {
            frame = requestAnimationFrame(tick)

            return
        }

        const refresh = clamp(24, 240, 1000 / Math.max(fastest, 1))

        const verdict = onWindow({
            index: index++,
            fps: (frames * 1000) / elapsed,
            budget: Math.min(BUDGET_CAP, refresh),
            stallShare: elapsed > 0 ? stalled / elapsed : 0,
        })

        if (verdict < 0) {
            stop()
        } else if (verdict === 0) {
            open()
        } else {
            rest(verdict)
        }
    }

    /* A tab that goes away mid-window invalidates it; one that comes back gets a
       moment to finish catching up before it is judged. */
    host.on(document, 'visibilitychange', () => {
        if (document.hidden) {
            clearPending()
            anchored = false
        } else if (running) {
            rest(SETTLE_MS)
        }
    })

    host.add(stop)

    /*
     * Long tasks are the cheap half of this module: the browser reports them,
     * nothing polls for them, and they cost nothing at all until the page is in
     * trouble. They are not used to judge anything -- they only wake the sampler
     * early, so the expensive half is asleep except when there is something
     * worth measuring.
     */
    if (
        typeof PerformanceObserver === 'function' &&
        Array.isArray(PerformanceObserver.supportedEntryTypes) &&
        PerformanceObserver.supportedEntryTypes.indexOf('longtask') !== -1
    ) {
        try {
            const observer = new PerformanceObserver((list) => {
                if (!running || !timer) {
                    return
                }

                list.getEntries().forEach((entry) => {
                    longTaskMs += entry.duration || 0
                })

                if (longTaskMs > WAKE_STALL_MS) {
                    open()
                }
            })

            observer.observe({ entryTypes: ['longtask'] })
            host.add(() => observer.disconnect())
        } catch (error) {
            // Reported as supported, refused anyway. The duty cycle covers it.
        }
    }

    return {
        start() {
            if (running) {
                return
            }

            running = true
            open()
        },
        stop,
    }
}

/* ------------------------------------------------------------------------- */
/* Entry point                                                                */
/* ------------------------------------------------------------------------- */

/**
 * @param {{gsap: import('gsap').gsap, ScrollTrigger: object, MOTION: object}} api
 * @returns {null}
 */
export function perfGovernor({ gsap }) {
    if (typeof window === 'undefined' || typeof document === 'undefined') {
        return null
    }

    if (!document.documentElement) {
        return null
    }

    gsapRef = gsap

    /* Undo the previous run before building anything: drop its listeners, its
       timers, its observer and its sampler, and sweep any style tag an
       interrupted run left behind. Both this and the rebuild below happen inside
       one synchronous boot, so there is no frame in which the panel is
       un-governed. */
    if (scope) {
        scope.dispose()
    }

    removeInjected()

    const host = createScope()
    scope = host
    sampler = null

    // gsap.context() reverts tweens, not listeners. A nested context is created
    // purely so the runtime's revert() reaches the disposer below.
    gsap.context(() => () => {
        host.dispose()

        // Hand GSAP and the document back exactly as they were found. The next
        // run re-applies everything in the same tick, so this is invisible
        // unless the runtime is stopping for good -- which is when it matters.
        if (gsapRef && gsapRef.ticker) {
            gsapRef.ticker.fps(240)
            gsapRef.ticker.lagSmoothing(500, 33)
        }

        const root = document.documentElement

        if (root && root.classList) {
            root.classList.remove(
                'vf-tier-high',
                'vf-tier-medium',
                'vf-tier-low',
                RESTRAINT_CLASS,
            )
        }

        removeInjected()
    })

    const first = base === null
    const measured = signals

    signals = readSignals()

    /* The device facts are re-read on every run, but what was MEASURED on this
       device carries over -- it is the most valuable line in the log and it
       would be a shame to lose it to a nav click. */
    if (measured) {
        signals.fps = measured.fps
        signals.budget = measured.budget
    }

    if (first) {
        base = judge(signals)
        tier = base.tier
        upgrades = base.hard || RANK[base.ceiling] <= RANK[base.tier] ? 0 : 1
    }

    /* A new page is a new set of widgets, tables and charts to keep up with, so
       the streaks start again and the watchdog goes back to looking often. What
       does NOT reset is `shift`: a tier the frame rate already argued down stays
       down. */
    badRun = 0
    goodRun = 0
    idleFor = IDLE_MS

    settle(first ? 'boot' : 'device re-read', first)
    publish()

    if (!logged) {
        logged = true
        info(`${describe()} -- override with localStorage["${STORAGE_KEY}"] = off|low|medium|high`)
    }

    /* A pinned or forced verdict is a decision, not an estimate: there is
       nothing for the watchdog to find out. */
    if (base.hard) {
        return null
    }

    /* At the bottom with no restoration left to earn, measuring costs the
       weakest device in the fleet something for no possible benefit. */
    if (tier === 'low' && !upgrades) {
        return null
    }

    const watchdog = createSampler(host, (sample) => {
        signals.fps = Math.round(sample.fps)
        signals.budget = Math.round(sample.budget)

        /* The first window is the "short real-frame-rate sample during the first
           second" -- and it overlaps the arrival sequence, Livewire booting and
           any chart parsing itself into existence. It is judged with a much
           lower bar, and its stalls are forgiven entirely, because the panel is
           legitimately busy. */
        const probe = sample.index === 0
        const floor = probe ? PROBE_FLOOR : BAD_FLOOR
        const share = probe ? PROBE_SHARE : BAD_SHARE

        const bad =
            sample.fps < floor ||
            sample.fps < sample.budget * share ||
            (!probe && sample.stallShare > STALL_SHARE)

        const excellent =
            !bad &&
            sample.fps >= GOOD_FLOOR &&
            sample.fps >= sample.budget * GOOD_SHARE &&
            sample.stallShare < GOOD_STALL_SHARE

        let changed = false

        if (bad) {
            goodRun = 0
            badRun += 1

            if (probe || badRun >= BAD_STREAK) {
                badRun = 0

                /* A device that has stumbled does not get to climb back later.
                   Withdrawing the allowance here is also what makes the "already
                   at the bottom" case terminal, and therefore free. */
                upgrades = 0

                if (RANK[tier] > 0) {
                    shift -= 1
                    settle(`sustained ${Math.round(sample.fps)}fps`, false)
                    changed = true
                }
            } else {
                // Unconfirmed. Stay awake and settle it inside a second or two,
                // rather than sleeping through the trouble.
                return 0
            }
        } else if (excellent) {
            badRun = 0
            goodRun += 1

            if (goodRun >= GOOD_STREAK && upgrades > 0 && RANK[tier] < RANK[base.ceiling]) {
                goodRun = 0
                upgrades -= 1
                shift += 1
                settle(`sustained ${Math.round(sample.fps)}fps`, false)
                changed = true
            }
        } else {
            badRun = 0
            goodRun = 0
        }

        if (tier === 'low' && !upgrades) {
            return -1
        }

        if (changed) {
            idleFor = IDLE_MS
        } else if (upgrades > 0 && RANK[tier] < RANK[base.ceiling]) {
            /* Still owed a decision. Look often enough that a fast machine is
               not held at a pessimistic guess for a whole minute. */
            idleFor = IDLE_EAGER_MS
        } else {
            /* Nothing wrong, and nothing left to prove. Back off -- a panel that
               has been smooth for five minutes does not need checking every
               five seconds, and this module's own cost should fall away as its
               answer becomes more certain. */
            idleFor = Math.min(Math.round(idleFor * IDLE_GROWTH), IDLE_MAX_MS)
        }

        return idleFor
    })

    sampler = watchdog
    host.add(() => {
        sampler = null
    })

    watchdog.start()

    return null
}
