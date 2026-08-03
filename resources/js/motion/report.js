/**
 * Service report -- the page read as a story.
 * -------------------------------------------
 * The report answers one question in four movements: which window are we
 * looking at, how does the traffic split by category, which domains dominate,
 * and who generated it. Flat, the page states all four at once. Scrolled, it
 * can tell them in order -- and that ordering is the whole feature here, not
 * the fades.
 *
 * What this module owns, and only on `.../{record}/report`:
 *
 *   ACT 1  the period picker. Nothing. It is a control, and a control that
 *          moves is a control you have to wait for.
 *   ACT 2  the category breakdown. Each bar measures itself out from zero as
 *          the section arrives, biggest first, and its hit count and
 *          percentage count up on exactly the same clock so the number and the
 *          bar are one reading rather than two coincidences.
 *   ACT 3  the most-visited domains, revealed in rank rhythm: the top entry
 *          arrives alone, travels furthest and takes longest; the tail closes
 *          up behind it and lands almost together. Rank is already the reason
 *          the list is ordered, so the motion says the same thing the data
 *          does.
 *   ACT 4  the most-active users, the same rhythm damped -- it is the
 *          supporting column, and it should read that way.
 *
 * Two acts that come into view together do not play together: the second waits
 * out the first (capped at MAX_WAIT). Without that, landing on a short report
 * where everything is above the fold produces three animations at once, which
 * is a burst, not a story.
 *
 * ---------------------------------------------------------------------------
 * WHY THIS MODULE TAKES THE CATEGORY BARS OFF counters.js
 *
 * `.vf-gauge-fill` is a panel-wide convention and counters.js sweeps every one
 * it finds, on its own per-element trigger and its own stagger. That is right
 * everywhere else, and wrong here: on this page the bar and the number beside
 * it are the same measurement, and two independent clocks make them land at
 * visibly unrelated moments.
 *
 * So this page-specific module takes the bars over -- the same move counters.js
 * itself makes against motion.css's `vf-measure` keyframes, and the reason
 * motion.js runs the page-specific set pieces after the generic content
 * behaviour. The handover is done in the same synchronous pass counters.js ran
 * in, before the browser has painted a frame, so nothing is visible mid-flight:
 * its trigger is killed, its tween is killed, the inline transform it wrote is
 * cleared, and the bar is back to exactly what the server rendered.
 *
 * ---------------------------------------------------------------------------
 * SAFETY
 *
 *   - Nothing is left unreadable. Rows are only hidden inside a section this
 *     module has measured as still ahead of the operator, and two wall-clock
 *     sweeps force anything hidden-but-on-screen back to full opacity whether
 *     or not its trigger ever fired.
 *   - Bars are never pre-collapsed. A bar at scaleX(0) is a plausible-looking
 *     wrong reading, so it only ever collapses at the instant its own tween is
 *     created, which is the instant its section is on screen.
 *   - Numbers are rewritten only while a tween owns them, and every exit from
 *     that tween -- complete, kill, re-boot, navigation, period change,
 *     wall-clock backstop -- puts the server's exact string back, whitespace
 *     included. The parser refuses anything it cannot reproduce character for
 *     character.
 *   - Every transform is transient and cleared on landing, so no card on this
 *     page becomes the containing block for a `position: fixed` action modal.
 *   - Nothing continuous: no rAF loop, no interval, no ambient layer. There is
 *     nothing here that needs stopping on `visibilitychange`.
 *   - Nothing is appended to the DOM and no stylesheet is injected, so the only
 *     things to clean up are listeners, wall-clock timers and in-flight text --
 *     all of which are.
 *
 * @param {{gsap: import('gsap').gsap, ScrollTrigger: object, MOTION: object}} api
 */

/* Only this page. `ServiceReport::route('/{record}/report')` is the one route
   in the panel that ends this way -- the neighbouring pages are /users,
   /connections, /traffic, /edit -- so the check cannot collide with the service
   user view, which renders a near-identical list-and-gauge layout. */
const REPORT_PATH = /\/report\/?$/

/* Filament's page wrapper; also the boundary for the delegated change listener,
   so a select anywhere else in the chrome cannot trigger a replay. */
const PAGE = '.fi-page'

/* The report's own markup, read from resources/views/filament/pages/
   service-report.blade.php. `.vf-gauge-fill` is the panel-wide gauge
   convention; `.divide-y` is what the two ranked lists are built with. */
const FILL = '.vf-gauge-fill'
const LIST = '.fi-section-content .divide-y'

/* Ceilings. A report can carry 25 domains; past this many the tail of a
   stagger is latency in front of a number somebody is trying to read. Rows
   beyond the cap are never touched, so they are simply already there. */
const MAX_LIST_ROWS = { high: 18, medium: 12, low: 0 }
const MAX_CATEGORY_ROWS = 10

/* How many numbers per row are allowed to count up. 2 takes the hit count and
   the percentage; 1 takes the hit count alone. */
const COUNTED_PER_ROW = { high: 2, medium: 1, low: 0 }

/* Only the head of a ranked list counts up. The point is emphasis, not a
   scoreboard animating twenty numbers at once on a two-core box. */
const COUNTED_LIST_ROWS = 3

/* Category bars: when the first one starts after its section arrives, and how
   far apart the rest follow it. */
const BAR_LEAD = 0.12
const BAR_STEP = 0.07

/* Longest one act will wait for the one before it. A beat is worth waiting
   for; a second is a page that feels slow. */
const MAX_WAIT = 0.4

/* Wall clock after which a period change is assumed to have morphed the DOM,
   in case `livewire:update` never arrives. */
const MORPH_FALLBACK = 1500

/* Failsafe sweeps: one for the ordinary case, one late enough to catch a
   section Livewire deferred past the first. */
const SWEEP_EARLY = 1400
const SWEEP_LATE = 3600

/* `number_format($int)`: comma-grouped, no decimals. */
const INTEGER = /^\d{1,3}(?:,\d{3})*$/

/* `round($x, 1)` rendered as "(12.5%)" or "(100%)" by the Blade. */
const PERCENT = /^\((\d{1,3}(?:\.\d)?)%\)$/

/*
 * Text nodes this module is part-way through rewriting, against the exact
 * string the server sent. Module scope, so it survives the runtime's
 * teardown/boot cycle: the closure that knows a number's original value is gone
 * by the time the next boot starts, and this is what lets that boot hand the
 * original back rather than leaving a half-counted figure on screen.
 */
const inFlight = new Map()

/* Deferred work belongs to the boot that created it. `livewire:navigated` and a
   reduced-motion toggle both re-run this module, and a callback from the
   previous run writing to an element the current run is animating would fight
   it. Stale generations bail. */
let generation = 0

let disposers = []
let timers = []

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

function clearTimers() {
    while (timers.length) {
        window.clearTimeout(timers.pop())
    }
}

/**
 * Rebuild the display string for an arbitrary value in the format the original
 * used, so every frame of a count is formatted the way the final frame will be.
 *
 * @param {number} value
 * @param {{decimals: number, group: boolean, prefix: string, suffix: string}} format
 * @returns {string}
 */
function renderNumber(value, format) {
    const fixed = Math.max(0, value).toFixed(format.decimals)
    const [whole, fraction] = fixed.split('.')
    const digits = format.group ? whole.replace(/\B(?=(\d{3})+(?!\d))/g, ',') : whole

    return format.prefix + digits + (fraction === undefined ? '' : `.${fraction}`) + format.suffix
}

/**
 * Take one of this page's two number shapes apart, or refuse.
 *
 * Deliberately narrower than the panel-wide parser in counters.js: this module
 * knows exactly which two Blade expressions produce these strings, so it can
 * demand an exact match instead of inferring one. Everything else on the page --
 * hostnames, user names, category labels, ":count domains" -- fails both
 * patterns and is left alone.
 *
 * The returned format is only trusted if re-rendering the parsed value
 * reproduces the original string character for character.
 *
 * @param {string} text  already trimmed
 * @returns {?{value: number, decimals: number, group: boolean, prefix: string, suffix: string}}
 */
function describeNumber(text) {
    if (INTEGER.test(text)) {
        const value = Number(text.replace(/,/g, ''))

        // Counting 0 or 1 up to itself is a frame of nothing.
        if (!Number.isFinite(value) || value < 2) {
            return null
        }

        const format = { value, decimals: 0, group: true, prefix: '', suffix: '' }

        return renderNumber(value, format) === text ? format : null
    }

    const percent = text.match(PERCENT)

    if (percent) {
        const value = Number(percent[1])

        if (!Number.isFinite(value) || value <= 0) {
            return null
        }

        const format = {
            value,
            decimals: percent[1].indexOf('.') === -1 ? 0 : 1,
            group: false,
            prefix: '(',
            suffix: '%)',
        }

        return renderNumber(value, format) === text ? format : null
    }

    return null
}

/**
 * Hand every number currently being counted back to the server's exact string.
 *
 * Called at the start of every boot, on teardown, on navigation, and -- most
 * importantly -- the moment the operator changes the period, before Livewire's
 * morph writes new figures that an in-flight count would otherwise overwrite
 * with stale ones.
 */
function restoreCounts(gsap) {
    if (!inFlight.size) {
        return
    }

    // Killing the tween fires its onInterrupt, which restores the node and
    // removes it from the map -- hence the snapshot, and hence the second pass
    // for anything whose tween had already gone.
    const entries = Array.from(inFlight.entries())

    entries.forEach(([, entry]) => gsap.killTweensOf(entry.state))

    entries.forEach(([node, entry]) => {
        if (node.isConnected) {
            node.nodeValue = entry.original
        }
    })

    inFlight.clear()
}

/**
 * Rank rhythm. Sub-linear, so the head of a list stands alone and the tail
 * closes up behind it: the eye is asked to follow one arrival, not twenty
 * equally weighted ones.
 */
function rankDelay(index) {
    return 0.075 * Math.pow(index, 0.72)
}

export function reportStory({ gsap, ScrollTrigger, MOTION }) {
    const boot = ++generation

    // Undo the previous run before anything else: restore any number left
    // mid-count, drop listeners, cancel wall-clock backstops.
    runDisposers()
    restoreCounts(gsap)

    if (!REPORT_PATH.test(window.location.pathname)) {
        return null
    }

    const page = document.querySelector(PAGE)

    if (!page) {
        return null
    }

    const governor = window.__vfMotion || {}
    const tier = governor.tier === 'high' || governor.tier === 'low' ? governor.tier : 'medium'

    // The runtime already skips every module under a reduced-motion preference.
    // This is the belt to that pair of braces, for the case where the governor
    // has decided on its own that this device should be left alone.
    if (governor.reduced) {
        return null
    }

    /*
     * Tweens created later -- from a period change, from a ScrollTrigger that
     * fires minutes after boot -- would otherwise be born outside the runtime's
     * gsap.context(): nothing would revert them, and the inline transforms they
     * left behind would survive the next navigation frozen mid-animation.
     * `gsap.context()` with no argument hands back the context we are currently
     * running inside, and `add()` puts later work back under it.
     */
    const host = gsap.context()

    const owned = (fn) => {
        if (host) {
            host.add(() => {
                fn()
            })

            return
        }

        fn()
    }

    // gsap.context() reverts tweens, not listeners or timers. This nested
    // context exists only so the runtime's revert() reaches the teardown below.
    gsap.context(() => () => {
        runDisposers()
        restoreCounts(gsap)
    })

    disposers.push(clearTimers)

    const later = (fn, ms) => {
        timers.push(window.setTimeout(fn, ms))
    }

    const onNavigating = () => {
        // Leaving mid-count would strand a number at whatever the last frame
        // wrote, and the outgoing page is on screen for the whole transition.
        restoreCounts(gsap)
        runDisposers()
    }

    document.addEventListener('livewire:navigating', onNavigating, { once: true })
    disposers.push(() => document.removeEventListener('livewire:navigating', onNavigating))

    const desktop = window.matchMedia('(min-width: 64rem)').matches
    const rtl = document.dir === 'rtl'

    /* Same choreography on a narrow viewport with the amplitude turned down:
       large offsets on a phone read as things falling into place rather than
       settling. */
    const amp = desktop ? 1 : 0.6

    /* Travel is leading-edge to resting position, so it flips with the
       document's direction rather than always coming from the left. */
    const direction = rtl ? 1 : -1

    const viewportHeight = () => window.innerHeight || document.documentElement.clientHeight || 0

    /* ------------------------------------------------------------------ *
     * Counting one number
     * ------------------------------------------------------------------ */

    /**
     * Count a single text node up to the value it already shows.
     *
     * Text NODES rather than elements, because the category row puts its hit
     * count and its percentage inside the same span: rewriting that element's
     * textContent would delete the inner span along with the percentage. A node
     * carries no attributes, so the in-flight bookkeeping lives in the
     * module-level map instead of on the DOM.
     */
    function countNode(node, delay, duration) {
        const original = node.nodeValue
        const text = original.trim()
        const format = describeNumber(text)

        if (!format) {
            return
        }

        // Everything either side of the token is whitespace, so the token can
        // only occur once and its position is the length of the leading run.
        const lead = original.slice(0, original.indexOf(text))
        const trail = original.slice(lead.length + text.length)

        const state = { value: 0 }
        let settled = false

        const settle = () => {
            if (settled) {
                return
            }

            settled = true
            inFlight.delete(node)

            if (node.isConnected) {
                // The original string, not a re-render of it: this restores the
                // surrounding whitespace exactly as Blade emitted it.
                node.nodeValue = original
            }
        }

        inFlight.set(node, { original, state })

        // Written before the tween starts so the delay is spent at zero rather
        // than at the real value -- otherwise the number visibly drops to zero
        // a beat after the row has arrived.
        node.nodeValue = lead + renderNumber(0, format) + trail

        gsap.to(state, {
            value: format.value,
            duration,
            delay,
            // power3.out rather than the panel's expo.out: expo puts almost all
            // of the movement in the first third, which on a counter reads as
            // the number snapping to its value and then sitting still.
            ease: MOTION.easeSoft,
            onUpdate() {
                if (node.isConnected) {
                    node.nodeValue = lead + renderNumber(state.value, format) + trail
                }
            },
            onComplete: settle,
            onInterrupt: settle,
        })

        // The number is the only thing on this page that is briefly wrong, so
        // its correctness cannot depend on GSAP finishing. This runs on the wall
        // clock regardless and no-ops if the tween already settled.
        later(settle, (delay + duration) * 1000 + 400)
    }

    /**
     * The numeric text nodes inside one row, in document order.
     *
     * Walking for text rather than querying for a class keeps this working if
     * the Blade's utility classes are reshuffled -- the shape of the number is
     * the contract, and `describeNumber` refuses everything that is not one.
     */
    function numberNodes(root, limit) {
        if (limit < 1) {
            return []
        }

        const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT)
        const found = []

        let node = walker.nextNode()

        while (node && found.length < limit) {
            if (node.nodeValue && describeNumber(node.nodeValue.trim())) {
                found.push(node)
            }

            node = walker.nextNode()
        }

        return found
    }

    /* ------------------------------------------------------------------ *
     * Reading the page
     * ------------------------------------------------------------------ */

    function collect() {
        const fills = Array.from(page.querySelectorAll(FILL))
        const categoryRows = []

        fills.forEach((fill) => {
            const track = fill.parentElement
            const row = track ? track.parentElement : null

            // A category row is the block that owns exactly one bar plus its
            // label line. If the markup ever moves and that stops being true,
            // this pass sits out rather than animating a wrapper.
            if (!row || row.querySelectorAll(FILL).length !== 1) {
                return
            }

            if (row.matches('.fi-section, .fi-section-content-ctn, .fi-section-content')) {
                return
            }

            categoryRows.push({ row, fill })
        })

        const categorySection = categoryRows.length
            ? categoryRows[0].row.closest('.fi-section')
            : null

        const lists = []

        page.querySelectorAll(LIST).forEach((list) => {
            // This page has no Filament table, and if one is ever added its rows
            // are re-created on every 30-60s poll -- an entrance on them would
            // re-fire forever and read as flicker. Belt and braces.
            if (list.closest('.fi-ta')) {
                return
            }

            const rows = Array.from(list.children)
            const section = list.closest('.fi-section')

            if (!rows.length || !section) {
                return
            }

            // The domains list carries a category badge on every entry; the
            // users list is names and counts. That difference is what decides
            // which of the two gets the heavier treatment, rather than the
            // document order, which a Blade edit could reverse.
            lists.push({ section, rows, badged: Boolean(rows[0].querySelector('.fi-badge')) })
        })

        return { categoryRows, categorySection, lists }
    }

    /* ------------------------------------------------------------------ *
     * Failsafes
     * ------------------------------------------------------------------ */

    /**
     * Force anything hidden but on screen back to full opacity.
     *
     * Insurance, not choreography. If a trigger never fires -- a mis-measured
     * refresh, a section that arrived late and shifted the page -- this is what
     * guarantees the operator can always read the report.
     */
    function sweepRows(rows) {
        if (!rows.length) {
            return
        }

        const height = viewportHeight()

        // All reads first, then all writes: interleaving them would be one
        // forced layout per row.
        const stuck = []

        rows.forEach((row) => {
            if (!row.isConnected) {
                return
            }

            const box = row.getBoundingClientRect()

            if (box.bottom < 0 || box.top > height) {
                return
            }

            if (Number(gsap.getProperty(row, 'opacity')) < 0.99) {
                stuck.push(row)
            }
        })

        if (!stuck.length) {
            return
        }

        owned(() =>
            gsap.to(stuck, {
                opacity: 1,
                x: 0,
                y: 0,
                scale: 1,
                duration: MOTION.fast,
                ease: MOTION.easeSoft,
                overwrite: true,
                clearProps: 'opacity,transform,transformOrigin,willChange',
            }),
        )
    }

    /**
     * A bar can only be short if a tween that owned it was killed part-way --
     * nothing here ever leaves one collapsed on purpose. Clearing the inline
     * transform puts it back to the width the server rendered, which is the
     * single source of truth for the value.
     *
     * `getTweensOf` rather than `isTweening`: a bar waiting out its stagger has
     * already had scaleX(0) written by its own `fromTo`, but counts as inactive,
     * and clearing the transform out from under it would show the bar snapping
     * to full and then measuring again. This only ever runs late enough that a
     * bar with no tween left on it is genuinely abandoned.
     */
    function sweepFills(fills) {
        fills.forEach((fill) => {
            if (!fill.isConnected || gsap.getTweensOf(fill).length) {
                return
            }

            if (Number(gsap.getProperty(fill, 'scaleX')) < 0.99) {
                gsap.set(fill, { clearProps: 'transform,willChange' })
            }
        })
    }

    /* ------------------------------------------------------------------ *
     * The build
     * ------------------------------------------------------------------ */

    /**
     * @param {'arrive'|'refresh'} mode  `arrive` is a page landing: acts wait
     *        for their section to reach the fold. `refresh` is the operator
     *        having just changed the period, so only what is already on screen
     *        replays, and it replays from a dimmed state rather than from
     *        nothing -- a deliberate action should never blank the page it
     *        was aimed at.
     */
    function build(mode) {
        const { categoryRows, categorySection, lists } = collect()

        if (!categoryRows.length && !lists.length) {
            return
        }

        const height = viewportHeight()

        // One measuring pass for every section, before a single write.
        const sections = []

        if (categorySection) {
            sections.push(categorySection)
        }

        lists.forEach((list) => sections.push(list.section))

        const boxes = new Map()
        sections.forEach((section) => boxes.set(section, section.getBoundingClientRect()))

        /**
         * A section is playable if it is rendered and the operator has not
         * already read past it. Anything above the viewport is left exactly as
         * the server sent it -- hiding content somebody has already scrolled by
         * to animate it back in behind them would be pure loss.
         */
        function playable(section) {
            const box = boxes.get(section)

            if (!box || box.height === 0 || box.bottom < 0) {
                return false
            }

            return mode === 'arrive' || box.top < height
        }

        /* The story clock. Acts book time on it in the order they come into
           view, so two sections arriving in the same frame still play one after
           the other. Reset per build, because a replay starts a new telling. */
        let bookedUntil = 0

        function book(length) {
            const now = performance.now() / 1000
            const wait = Math.min(Math.max(bookedUntil - now, 0), MAX_WAIT)

            bookedUntil = now + wait + length

            return wait
        }

        /* Everything this build hid, for the sweeps. */
        const hidden = []

        /**
         * Play `act` when `section` reaches the fold -- or immediately, if the
         * operator has just asked for new figures and is looking at it.
         */
        function schedule(section, act) {
            if (mode === 'refresh') {
                act()

                return
            }

            ScrollTrigger.create({
                trigger: section,
                start: 'top 88%',
                // Scrolling back up must not replay anything. These are figures
                // an operator is comparing, not slides.
                once: true,
                onEnter: act,
            })
        }

        /* -------------------------------------------------------------- *
         * ACT 2 -- the category breakdown measures itself out
         * -------------------------------------------------------------- */

        if (categorySection && playable(categorySection)) {
            const rows = categoryRows.slice(0, MAX_CATEGORY_ROWS)

            // The handover from counters.js. Done here, synchronously, in the
            // same pass that module ran in: its `fromTo` renders its start
            // state the moment it is created, so by killing it and clearing the
            // inline transform before the browser paints, the bar never shows
            // a frame of either module's work.
            const bars = rows.map((entry) => entry.fill)

            ScrollTrigger.getAll().forEach((trigger) => {
                if (trigger.trigger && bars.indexOf(trigger.trigger) !== -1) {
                    trigger.kill()
                }
            })

            gsap.killTweensOf(bars)
            gsap.set(bars, { clearProps: 'transform,transformOrigin,willChange' })

            // `animation: none` neutralises motion.css's `vf-measure` keyframes
            // the same way counters.js does, so exactly one sweep happens
            // whether or not that rule is still in the stylesheet. The origin is
            // restated because clearing `transform` does not carry it.
            gsap.set(bars, { animation: 'none', transformOrigin: 'left center' })

            const moves = tier !== 'low'
            const counted = COUNTED_PER_ROW[tier]

            if (moves && mode === 'arrive') {
                const blocks = rows.map((entry) => entry.row)

                gsap.set(blocks, { opacity: 0, y: 14 * amp })
                hidden.push(...blocks)
            } else if (moves) {
                const blocks = rows.map((entry) => entry.row)

                gsap.set(blocks, { opacity: 0.4 })
                hidden.push(...blocks)
            }

            const span = (index) => Math.max(MOTION.base, MOTION.slow - index * BAR_STEP)
            const length = BAR_LEAD + (rows.length - 1) * BAR_STEP + span(rows.length - 1)

            schedule(categorySection, () => {
                if (boot !== generation) {
                    return
                }

                const wait = book(length)

                owned(() => {
                    rows.forEach(({ row, fill }, index) => {
                        if (!row.isConnected || !fill.isConnected) {
                            return
                        }

                        // Every bar on the low tier measures out together in one
                        // short move: the effect is worth keeping -- it is what
                        // says a reading was taken -- the choreography is not.
                        const at = tier === 'low' ? 0 : BAR_LEAD + index * BAR_STEP
                        const run = tier === 'low' ? MOTION.base : span(index)

                        if (moves) {
                            gsap.set(row, { willChange: 'transform, opacity' })

                            gsap.to(row, {
                                opacity: 1,
                                y: 0,
                                duration: MOTION.base,
                                delay: wait + index * 0.055,
                                ease: MOTION.ease,
                                overwrite: 'auto',
                                clearProps: 'opacity,transform,willChange',
                            })
                        }

                        gsap.set(fill, { willChange: 'transform' })

                        // No overshoot, unlike the rest of the panel's spring
                        // work: a gauge that bounces past its mark is a gauge
                        // briefly reporting a number the server did not send.
                        gsap.fromTo(
                            fill,
                            { scaleX: 0 },
                            {
                                scaleX: 1,
                                duration: run,
                                delay: wait + at,
                                ease: MOTION.ease,
                                overwrite: 'auto',
                                // The bar's width is the truth; the transform
                                // only scales it, so it goes when it is done.
                                clearProps: 'transform,willChange',
                            },
                        )

                        // Same clock as the bar it belongs to. This is the whole
                        // reason the bars were taken off the generic pass.
                        numberNodes(row, counted).forEach((node) => {
                            countNode(node, wait + at, run * 0.85)
                        })
                    })
                })
            })

            later(() => {
                if (boot === generation) {
                    sweepFills(bars)
                }
            }, SWEEP_LATE)
        }

        /* -------------------------------------------------------------- *
         * ACTS 3 & 4 -- the ranked lists
         * -------------------------------------------------------------- */

        const maxRows = MAX_LIST_ROWS[tier]

        lists.forEach((list) => {
            if (!maxRows || !playable(list.section)) {
                return
            }

            const rows = list.rows.slice(0, maxRows)

            if (!rows.length) {
                return
            }

            // The supporting column is damped rather than given a different
            // rhythm: same phrasing, quieter, which is what "secondary" reads as.
            const weight = list.badged ? 1 : 0.7

            /* Rank is the only thing that varies down the list, and all three
               of these say the same thing about it: the head arrives from
               furthest away, takes longest, and has the stage to itself. */
            const travelFor = (index) =>
                (index === 0 ? 24 : index < 3 ? 15 : 8) * amp * weight * direction

            const runFor = (index) =>
                index === 0
                    ? MOTION.slow * 0.85
                    : index < 3
                      ? MOTION.base * 1.15
                      : MOTION.fast * 1.6

            const offsetFor = (index) => rankDelay(index) * weight

            if (mode === 'arrive') {
                gsap.set(rows, {
                    opacity: 0,
                    x: travelFor,
                    // The head entry scales from its leading edge, so it grows
                    // into place rather than swelling from its middle.
                    scale: (index) => (index === 0 && tier === 'high' ? 0.985 : 1),
                    transformOrigin: rtl ? '100% 50%' : '0% 50%',
                })
            } else {
                gsap.set(rows, { opacity: 0.4 })
            }

            hidden.push(...rows)

            const length = offsetFor(rows.length - 1) + runFor(rows.length - 1)

            schedule(list.section, () => {
                if (boot !== generation) {
                    return
                }

                // A morph between build and arrival can have replaced rows this
                // closure still points at. Animating a detached node is silent,
                // which is exactly why it has to be filtered out here rather
                // than trusted to fail loudly.
                const live = rows.filter((row) => row.isConnected)

                if (!live.length) {
                    return
                }

                const wait = book(length)

                owned(() => {
                    gsap.set(live, { willChange: 'transform, opacity' })

                    gsap.to(live, {
                        opacity: 1,
                        x: 0,
                        scale: 1,
                        duration: runFor,
                        ease: MOTION.ease,
                        // Function-based stagger: the offset is a property of
                        // the entry's RANK, not a constant tick down the list.
                        stagger: (index) => wait + offsetFor(index),
                        overwrite: 'auto',
                        clearProps: 'opacity,transform,transformOrigin,willChange',
                    })

                    if (tier !== 'high') {
                        return
                    }

                    // The top entry's category badge lands a beat after the row
                    // does -- the one accent in the list, on the one line that
                    // is the answer to "what are they actually visiting".
                    const badge = list.badged ? live[0].querySelector('.fi-badge') : null

                    if (badge) {
                        gsap.fromTo(
                            badge,
                            { scale: 0.86 },
                            {
                                scale: 1,
                                duration: MOTION.base,
                                delay: wait + 0.14,
                                ease: MOTION.spring,
                                transformOrigin: '50% 50%',
                                overwrite: 'auto',
                                clearProps: 'transform,transformOrigin',
                            },
                        )
                    }

                    live.slice(0, COUNTED_LIST_ROWS).forEach((row, index) => {
                        numberNodes(row, 1).forEach((node) => {
                            countNode(node, wait + offsetFor(index), runFor(index) * 0.9)
                        })
                    })
                })
            })
        })

        if (!hidden.length) {
            return
        }

        later(() => {
            if (boot === generation) {
                sweepRows(hidden)
            }
        }, SWEEP_EARLY)

        later(() => {
            if (boot === generation) {
                sweepRows(hidden)
            }
        }, SWEEP_LATE)
    }

    build('arrive')

    /* ------------------------------------------------------------------ *
     * ACT 1 -- the period picker, which is the one control on the page
     *
     * The picker does not move, but it does start the story again: changing the
     * window replaces every figure on the page, and re-measuring them is the
     * panel showing it just took a new reading rather than quietly swapping the
     * numbers out underneath the operator.
     *
     * Gated on a real `change` on this page's select -- not on `livewire:update`
     * alone, which also fires for sidebar badge polling and would otherwise
     * replay the report on a timer.
     * ------------------------------------------------------------------ */

    let awaitingMorph = false

    const replay = () => {
        if (boot !== generation || !awaitingMorph || !page.isConnected) {
            return
        }

        awaitingMorph = false
        owned(() => build('refresh'))
    }

    const onChange = (event) => {
        const target = event.target

        if (!target || target.tagName !== 'SELECT' || !page.contains(target)) {
            return
        }

        // The morph is about to rewrite every number on the page. Hand the
        // originals back now, before it does, so a count still in flight cannot
        // restore a figure from the previous period over the new one.
        restoreCounts(gsap)

        awaitingMorph = true
        later(replay, MORPH_FALLBACK)
    }

    const onUpdate = () => {
        if (!awaitingMorph) {
            return
        }

        // One frame after the morph, so the new rows are in the document and
        // measurable. `replay` is guarded, so the wall-clock fallback above
        // becomes a no-op once this has run.
        later(replay, 16)
    }

    document.addEventListener('change', onChange)
    disposers.push(() => document.removeEventListener('change', onChange))

    document.addEventListener('livewire:update', onUpdate)
    disposers.push(() => document.removeEventListener('livewire:update', onUpdate))

    return null
}
