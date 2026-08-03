/**
 * Inline sparklines and micro-bars.
 * ---------------------------------
 * The brief for this module was "draw a per-user trend beside the allowance and
 * traffic figures in the users table". The honest answer, after reading the
 * resource, the model and the rendered markup, is that **the panel does not send
 * a per-user series to the browser**. `ServiceUsersTable` renders four scalars
 * per row -- used bytes, the limit, cumulative in, cumulative out -- and the
 * per-user view page renders the same scalars again. The history exists
 * (`bandwidth_samples` carries a `service_user_id`), but nothing puts it in the
 * DOM, and no amount of client-side cleverness can recover a trend from a single
 * number. Drawing a plausible-looking wiggle next to a real figure in a
 * monitoring panel would be inventing telemetry, so this module does not do it.
 * Giving a row a real trend line needs a backend change -- see the note at the
 * bottom of this file.
 *
 * What it draws instead is built entirely out of figures the server already
 * rendered:
 *
 *   1. RATIO BARS, in any table column whose cells carry a "NN%" description.
 *      That is the allowance column: the percentage under "1.9 GB of 10 GB" is
 *      the ratio the server computed, capped at 1, and the bar is exactly that
 *      long. Nothing is derived, nothing is scaled.
 *
 *   2. MAGNITUDE BARS, in any table column where every cell that has a value
 *      parses as a byte quantity -- the traffic column here, `bytes_in` and
 *      `bytes_out` in the connection log. A bar's length is its row's share of
 *      the largest value in that column ON THIS PAGE, which turns a column of
 *      formatted byte strings into something you can scan for the heavy user.
 *      Where the cell also has a byte description (traffic renders "X in" above
 *      "Y out"), the bar is split at the in/out boundary, so its length is the
 *      volume and its division is the direction.
 *
 *   3. A CARD SPARKLINE -- the only real multi-point line here -- driven by the
 *      bandwidth chart's own series. Filament renders that series into the
 *      chart's `x-data` attribute and then republishes it on every poll through
 *      the `updateChartData` event, so it is available, complete and live. It is
 *      drawn into the bottom strip of the stats card whose value is a byte
 *      quantity, in the same slot and geometry Filament uses for its own stat
 *      charts, and its tooltip is assembled from the chart's own heading,
 *      dataset labels and bucket labels -- every word of it server-localised, so
 *      this file states what the line is without inventing a single string.
 *
 * RULES THIS FILE HOLDS ITSELF TO
 *
 *   - Nothing is hidden. A bar's WIDTH is the truth and is written once; the
 *     draw-in is `scaleX(0 -> 1)`, whose end state is the identity transform.
 *     Every way out of that tween -- completion, interruption, a context revert,
 *     a wall-clock failsafe -- leaves a fully drawn bar at its true width. There
 *     is no code path that can leave a value misreported or a bar stuck empty.
 *
 *   - Table ROWS are never animated and never moved. Every bar is absolutely
 *     positioned inside the cell's own bottom padding, so adding one changes no
 *     row's height and shifts no text.
 *
 *   - Nothing keeps a live transform. The bars end at identity and hand their
 *     inline transform back; the card sparkline clears its clip on landing.
 *     Filament renders action modals inline inside tables, and a surviving
 *     transform would make the cell their containing block.
 *
 *   - The draw-in happens ONCE per boot. Tables here poll every 30-60s and the
 *     poll re-creates the rows; re-staging the animation on every poll would
 *     make the table twitch twice a minute. Polls rebuild the bars silently,
 *     from the freshly rendered numbers.
 *
 * @param {{gsap: import('gsap').gsap, MOTION: object}} api
 */

/* Marks every node this module appends. The runtime reverts our tweens but
   cannot delete DOM we created, so each run removes the previous run's nodes
   before it builds, and sweeps orphans by attribute if a run was interrupted. */
const OWNED = 'data-vf-spark'

/* The class that makes a table cell's text block the positioning context for
   its bar. Verified: nothing in Filament's `tables/resources/css/columns/text.css`
   sets `position` on `.fi-ta-text`, so this introduces no conflict -- and
   `position: relative` (unlike a transform) does not capture fixed-position
   descendants, so the action modals Filament renders inline stay anchored to the
   viewport. */
const HOST_CLASS = 'vf-spark-host'

const STYLE_ID = 'vf-sparklines'

/* Byte quantities as this app formats them: `round($value, 1).' '.$unit` with
   an English unit suffix, in every locale (see ServiceUsersTable::formatBytes).
   Values never exceed 1023.9 before the next unit, so a thousands separator is
   impossible and a comma can only be a decimal point. The negative lookahead is
   what stops "10.8.0.2" or a version string being read as a quantity. */
const BYTES = /^\s*([0-9]+(?:[.,][0-9]+)?)\s*(B|KB|MB|GB|TB)(?![\p{L}\p{N}])/u

/* `:percent% used` -- the percentage leads the string in en/fr/de/es/it/pt
   alike, which is what makes reading it locale-independent. */
const PERCENT = /^\s*([0-9]{1,3}(?:[.,][0-9]+)?)\s*%/

const UNIT = { B: 1, KB: 1024, MB: 1048576, GB: 1073741824, TB: 1099511627776 }

/* Capability tiers, ordered. An unknown tier resolves to `medium`, which is what
   the governor documents as the safe default for a name it does not recognise. */
const RANK = { low: 0, medium: 1, high: 2 }

/* Ceiling on bars per page, by tier. A table showing 50 rows of a 4-column byte
   log would otherwise get 200 of them, which is a lot of nodes for decoration. */
const MAX_BARS = [40, 90, 160]

/* Below this width the users table is Filament's stacked-on-mobile layout, whose
   cells have no vertical padding for a bar to sit in -- and a 2px bar is not
   what anyone came to a phone-sized dashboard for. */
const WIDE = '(min-width: 1024px)'

/* Cells examined per table. A pathological page cannot turn this into a scan of
   thousands of nodes. */
const MAX_CELLS = 600

/* Points in the card sparkline. The windows this panel actually renders are 24,
   28 or 30 buckets; anything longer is reduced by taking the PEAK of each chunk,
   which cannot understate a throughput trend. */
const MAX_POINTS = 120

/* Rebuilds allowed to run synchronously inside one frame before the rest are
   deferred. Livewire's morph can arrive as several mutation batches; this
   converges on the first one or two and refuses to spin on the rest. */
const MAX_SYNC_REBUILDS = 4

const CSS = `
/* Track and fill. Both take their colour from one custom property so the danger
   variant is a single override rather than a second copy of every rule, and both
   colours are Filament's own -- no new palette enters the panel here. */
.vf-spark {
    --vf-spark-ink: var(--primary-500, #f59e0b);

    position: absolute;
    inset-inline: 0.75rem;
    bottom: 0.375rem;
    height: 2px;
    border-radius: 999px;
    overflow: hidden;
    pointer-events: none;
    background-color: color-mix(in oklab, var(--vf-spark-ink) 18%, transparent);
}

.dark .vf-spark {
    --vf-spark-ink: var(--primary-400, #fbbf24);
}

/* The column already renders its value in red when a user is over their
   allowance (TextColumn emits \`fi-color fi-color-danger\`). The bar follows it,
   so the cell says one thing in two ways rather than two things. */
.vf-spark-danger {
    --vf-spark-ink: var(--danger-500, #ef4444);
}

.dark .vf-spark-danger {
    --vf-spark-ink: var(--danger-400, #f87171);
}

/* The fill's WIDTH is the value. The draw-in only ever scales it, so the number
   on screen cannot be misreported by an animation, and the resting state is the
   identity transform. */
.vf-spark-fill {
    display: flex;
    height: 100%;
    border-radius: inherit;
    transform-origin: left center;
}

/* Flex direction follows the document, so the split lands on the correct side in
   RTL without a mirrored copy of the rule. */
[dir='rtl'] .vf-spark-fill {
    transform-origin: right center;
}

.vf-spark-fill > span {
    display: block;
    height: 100%;
    background-color: var(--vf-spark-ink);
}

/* The trailing segment -- bytes out, where a cell carries both directions. Same
   ink, lower presence: one bar with a division in it, not two bars. */
.vf-spark-fill > span + span {
    opacity: 0.42;
}

.vf-spark-host {
    position: relative;
}

/* The card sparkline occupies exactly the strip Filament gives its own stat
   charts (\`absolute inset-x-0 bottom-0 ... h-6\`), so a card that grows one is
   the shape Filament already draws, not a new component. */
.vf-spark-card {
    --vf-spark-ink: var(--primary-500, #f59e0b);

    position: absolute;
    inset-inline: 0;
    bottom: 0;
    height: 1.5rem;
    overflow: hidden;
    border-end-start-radius: 0.75rem;
    border-end-end-radius: 0.75rem;
    pointer-events: none;
}

.dark .vf-spark-card {
    --vf-spark-ink: var(--primary-400, #fbbf24);
}

.vf-spark-card > svg {
    display: block;
    width: 100%;
    height: 100%;
}

.vf-spark-area {
    fill: color-mix(in oklab, var(--vf-spark-ink) 16%, transparent);
    stroke: none;
}

.vf-spark-line {
    fill: none;
    stroke: var(--vf-spark-ink);
    stroke-width: 1.5;
    stroke-linecap: round;
    stroke-linejoin: round;
}
`

/**
 * The previous run's teardown. Module scope, because the runtime re-invokes this
 * function on every `livewire:navigated` without re-importing the module, and a
 * run has to be able to undo the one before it even if the context revert that
 * normally does so never happened.
 */
let activeScope = null

/**
 * A bag of teardown callbacks: every listener, observer, timer and appended node
 * goes through one, so nothing outlives a context revert.
 */
function createScope() {
    const disposers = []
    let disposed = false

    return {
        get disposed() {
            return disposed
        },
        on(target, type, handler, options) {
            if (!target || typeof target.addEventListener !== 'function') {
                return
            }

            target.addEventListener(type, handler, options)
            disposers.push(() => target.removeEventListener(type, handler, options))
        },
        add(disposer) {
            disposers.push(disposer)
        },
        dispose() {
            if (disposed) {
                return
            }

            disposed = true

            while (disposers.length) {
                try {
                    disposers.pop()()
                } catch (error) {
                    // A failing disposer must not strand the ones behind it.
                    console.warn('[vpn-forge motion] sparklines cleanup failed', error)
                }
            }
        },
    }
}

/**
 * Remove every bar and sparkline on the page, and un-mark their hosts.
 *
 * Runs at the start of every build -- a rebuild always re-reads the figures
 * rather than trying to patch what is there -- and again on teardown, so an
 * interrupted run cannot leave orphans for the next one to trip over.
 *
 * Deliberately does NOT touch the injected stylesheet: that is torn down by id,
 * because a sweep by attribute here would delete the rules the bars are drawn
 * with on the first rebuild and leave every bar an invisible empty span.
 */
function purge() {
    document.querySelectorAll(`[${OWNED}]`).forEach((node) => node.remove())
    document.querySelectorAll(`.${HOST_CLASS}`).forEach((node) => node.classList.remove(HOST_CLASS))
}

/** Drop any stylesheet left by a previous run, including one an interrupted run
 *  never got to remove. By id, so it is found whether or not this run put it
 *  there. */
function dropStylesheet() {
    const existing = document.getElementById(STYLE_ID)

    if (existing) {
        existing.remove()
    }
}

/**
 * Read a formatted byte quantity, in bytes. Returns null for anything that is
 * not unambiguously one -- a suffix in any language is fine ("738.6 MB in",
 * "738,6 Mo"-style locales are refused because the unit would not match), a
 * missing unit is not.
 *
 * @param {string} text
 * @returns {?number}
 */
function parseBytes(text) {
    if (typeof text !== 'string') {
        return null
    }

    const found = text.match(BYTES)

    if (!found) {
        return null
    }

    const value = Number(found[1].replace(',', '.')) * UNIT[found[2]]

    return Number.isFinite(value) ? value : null
}

/**
 * Read a leading percentage as a 0..1 ratio, clamped. The server already caps
 * the allowance ratio at 1; the clamp here is for anything that does not.
 *
 * @param {string} text
 * @returns {?number}
 */
function parseRatio(text) {
    if (typeof text !== 'string') {
        return null
    }

    const found = text.match(PERCENT)

    if (!found) {
        return null
    }

    const value = Number(found[1].replace(',', '.')) / 100

    if (!Number.isFinite(value)) {
        return null
    }

    return Math.min(1, Math.max(0, value))
}

/**
 * The `fi-ta-cell-<column>` token on a cell, which is what identifies the column
 * a cell belongs to. Filament derives it from the column name
 * (`Column::getCellAttributeHtml`), so it is stable across a re-render and
 * across pagination.
 *
 * @param {Element} cell
 * @returns {?string}
 */
function columnKeyOf(cell) {
    for (const name of cell.classList) {
        if (name.length > 11 && name.startsWith('fi-ta-cell-')) {
            return name
        }
    }

    return null
}

/**
 * Everything in one table cell that a bar could be built from, or null if the
 * cell holds no value at all. A cell whose state is blank renders a
 * `.fi-ta-placeholder` and no `.fi-ta-text-item`, so it lands here as null and
 * takes no part in deciding what its column is.
 *
 * Pure reads -- no writes happen anywhere in this pass.
 *
 * @param {Element} cell
 * @returns {?object}
 */
function readCell(cell) {
    const host = cell.querySelector('.fi-ta-text')

    if (!host) {
        return null
    }

    // A line-clamped column renders as `-webkit-box`, which mangles children
    // that are not text. Leave those alone.
    if (host.style.getPropertyValue('--line-clamp')) {
        return null
    }

    // With a description the wrapper and the state are separate elements; with a
    // single state and no description Filament merges both class sets onto one
    // element (see TextColumn's `$stateCount === 1 && ! $hasDescriptions` path).
    const item = host.classList.contains('fi-ta-text-item')
        ? host
        : host.querySelector(':scope > .fi-ta-text-item')

    if (!item) {
        return null
    }

    const description = host.querySelector(':scope > .fi-ta-text-description')

    return {
        host,
        state: item.textContent,
        description: description ? description.textContent : '',
        danger: item.classList.contains('fi-color-danger'),
    }
}

/**
 * Group a table's body cells by column. Group-header rows are skipped: they hold
 * summaries, not records.
 *
 * @param {Element} table
 * @returns {Map<string, object[]>}
 */
function readTable(table) {
    const columns = new Map()
    const rows = table.querySelectorAll('tbody .fi-ta-row:not(.fi-ta-group-header-row)')

    let seen = 0

    for (const row of rows) {
        for (const cell of row.children) {
            if (seen >= MAX_CELLS) {
                return columns
            }

            if (!cell.classList.contains('fi-ta-cell')) {
                continue
            }

            const key = columnKeyOf(cell)

            if (!key) {
                continue
            }

            seen += 1

            const read = readCell(cell)

            if (!read) {
                continue
            }

            const bucket = columns.get(key)

            if (bucket) {
                bucket.push(read)
            } else {
                columns.set(key, [read])
            }
        }
    }

    return columns
}

/**
 * Decide what a column is, and work out every bar in it.
 *
 * The test is deliberately all-or-nothing: a column qualifies only if EVERY cell
 * that has a value parses. One unparseable value means the column is something
 * else that happens to contain numbers, and it gets nothing -- the same "guilty
 * until proven innocent" rule the counters module applies to stat values.
 *
 * @param {object[]} cells
 * @returns {object[]} plans, each { host, ratio, split, danger }
 */
function planColumn(cells) {
    if (!cells.length) {
        return []
    }

    /* --- Ratio column. The description already IS the answer. ------------- */

    const ratios = cells.map((cell) => parseRatio(cell.description))

    if (ratios.every((ratio) => ratio !== null)) {
        return cells.map((cell, index) => ({
            host: cell.host,
            ratio: ratios[index],
            split: null,
            danger: cell.danger,
        }))
    }

    /* --- Byte column. Length is the row's share of the page's largest. ----- */

    const totals = []
    const splits = []

    for (const cell of cells) {
        const head = parseBytes(cell.state)

        if (head === null) {
            return []
        }

        // A byte description is the other half of the same measurement (traffic
        // renders "X in" above "Y out"). Anything else is prose and is ignored
        // rather than guessed at.
        const tail = parseBytes(cell.description)
        const total = head + (tail === null ? 0 : tail)

        totals.push(total)
        splits.push(tail === null || total <= 0 ? null : head / total)
    }

    const peak = Math.max(...totals)

    if (!Number.isFinite(peak) || peak <= 0) {
        return []
    }

    return cells.map((cell, index) => ({
        host: cell.host,
        ratio: totals[index] / peak,
        split: splits[index],
        danger: cell.danger,
    }))
}

/**
 * The bar itself. Three nodes: a track, a fill whose width is the value, and one
 * or two segments inside it. `aria-hidden` because the figure it draws is the
 * text directly above it -- a screen reader gains nothing from a second copy.
 *
 * @param {object} plan
 * @returns {HTMLElement}
 */
function buildBar(plan) {
    const track = document.createElement('span')

    track.className = plan.danger ? 'vf-spark vf-spark-danger' : 'vf-spark'
    track.setAttribute(OWNED, 'bar')
    track.setAttribute('aria-hidden', 'true')

    const fill = document.createElement('span')

    fill.className = 'vf-spark-fill'
    // Rounded to a tenth of a percent: enough resolution for a 200px cell, and
    // it keeps the inline style short.
    fill.style.width = `${(Math.min(1, Math.max(0, plan.ratio)) * 100).toFixed(1)}%`

    const head = document.createElement('span')

    if (plan.split === null) {
        head.style.flex = '1 1 auto'
        fill.appendChild(head)
    } else {
        const share = Math.min(1, Math.max(0, plan.split))

        head.style.flex = `0 0 ${(share * 100).toFixed(1)}%`
        fill.appendChild(head)

        const tail = document.createElement('span')

        tail.style.flex = '1 1 auto'
        fill.appendChild(tail)
    }

    track.appendChild(fill)

    return track
}

/**
 * Pull the bandwidth chart's series out of the markup Filament renders.
 *
 * The series is in the widget's `x-data` attribute, as
 * `cachedData: JSON.parse('...')` -- Blade's `@js()` emits an object as a
 * `JSON.parse` call over a string in which every quote and apostrophe has been
 * hex-escaped (`Illuminate\Support\Js::convertJsonToJavaScriptExpression`).
 * Because no raw apostrophe can survive that encoding, the closing quote is
 * unambiguous, and because no raw double quote can either, the body is already a
 * valid JSON string literal -- so two parses, no hand-rolled unescaping.
 *
 * This is string surgery on someone else's encoder, so it is treated as
 * best-effort: any failure returns null and the card simply waits for the first
 * `updateChartData` event instead, which carries the same object already parsed.
 *
 * @param {string} attr
 * @returns {?object}
 */
function extractCachedData(attr) {
    const at = attr.indexOf('cachedData')

    if (at < 0) {
        return null
    }

    const tail = attr.slice(at)
    const quoted = tail.match(/JSON\.parse\('((?:[^'\\]|\\.)*)'\)/)

    if (quoted) {
        try {
            return JSON.parse(JSON.parse(`"${quoted[1]}"`))
        } catch (error) {
            return null
        }
    }

    // Fallback for an encoder that emits the object literally: match its braces,
    // ignoring anything inside a string.
    const open = tail.indexOf('{')

    if (open < 0) {
        return null
    }

    let depth = 0
    let inString = false
    let escaped = false

    for (let i = open; i < tail.length; i += 1) {
        const char = tail[i]

        if (escaped) {
            escaped = false
            continue
        }

        if (char === '\\') {
            escaped = true
            continue
        }

        if (char === '"') {
            inString = !inString
            continue
        }

        if (inString) {
            continue
        }

        if (char === '{') {
            depth += 1
        } else if (char === '}') {
            depth -= 1

            if (depth === 0) {
                try {
                    return JSON.parse(tail.slice(open, i + 1))
                } catch (error) {
                    return null
                }
            }
        }
    }

    return null
}

/**
 * Turn a Chart.js payload into one series plus the words that describe it.
 *
 * The datasets are summed element-wise. On this panel that is download plus
 * upload, which is the same total the "Bandwidth today" card reports -- adding
 * two real series is arithmetic, not invention, and the tooltip names both.
 *
 * @param {object} payload
 * @returns {?{values: number[], label: string}}
 */
function readSeries(payload) {
    if (!payload || !Array.isArray(payload.datasets)) {
        return null
    }

    const labels = Array.isArray(payload.labels) ? payload.labels : []

    let values = null
    const names = []

    for (const dataset of payload.datasets) {
        // The upper bound is a guard, not a policy: the reductions below spread
        // arrays into Math.max, and a pathological payload should be refused
        // rather than blow the argument limit.
        if (!dataset || !Array.isArray(dataset.data) || dataset.data.length < 2 || dataset.data.length > 5000) {
            continue
        }

        const numbers = dataset.data.map((entry) => (Number.isFinite(entry) ? entry : 0))

        if (values === null) {
            values = numbers
        } else if (values.length === numbers.length) {
            values = values.map((value, index) => value + numbers[index])
        } else {
            // Ragged datasets are not a series this can add up honestly.
            return null
        }

        if (typeof dataset.label === 'string' && dataset.label !== '') {
            names.push(dataset.label)
        }
    }

    if (values === null) {
        return null
    }

    // Peak-preserving reduction. Taking the maximum of each chunk can only ever
    // overstate a quiet stretch, never hide a spike -- the wrong direction to be
    // wrong in on a throughput trend.
    if (values.length > MAX_POINTS) {
        const size = Math.ceil(values.length / MAX_POINTS)
        const reduced = []

        for (let i = 0; i < values.length; i += size) {
            reduced.push(Math.max(...values.slice(i, i + size)))
        }

        values = reduced
    }

    const span =
        labels.length > 1 ? `${labels[0]} → ${labels[labels.length - 1]}` : ''

    return {
        values,
        label: [names.join(' + '), span].filter(Boolean).join(' · '),
    }
}

/**
 * Build the sparkline's geometry. Coordinates are in a 100x24 viewBox stretched
 * to the card, with the stroke held at a constant width by `non-scaling-stroke`
 * so the distortion never reaches the line itself.
 *
 * @param {number[]} values
 * @returns {?{line: string, area: string}}
 */
function plotSeries(values) {
    if (!Array.isArray(values) || values.length < 2) {
        return null
    }

    const peak = Math.max(...values)
    const step = 100 / (values.length - 1)
    const floor = 22
    const height = 18

    const points = values.map((value, index) => {
        const x = index * step
        // A flat line along the bottom is what "no traffic in this window" looks
        // like, and it is the truth -- it is drawn, not skipped.
        const y = peak > 0 ? floor - (value / peak) * height : floor

        return `${x.toFixed(2)},${y.toFixed(2)}`
    })

    return {
        line: points.join(' '),
        area: `M0,24 L${points.join(' L')} L100,24 Z`,
    }
}

/**
 * @param {{gsap: import('gsap').gsap, MOTION: object}} api
 */
export function sparklines({ gsap, MOTION }) {
    if (typeof document === 'undefined' || !document.body) {
        return null
    }

    // Undo the previous run before anything else, whether or not its context was
    // reverted, then clear whatever an interrupted run left behind.
    if (activeScope) {
        activeScope.dispose()
        activeScope = null
    }

    purge()
    dropStylesheet()

    const main = document.querySelector('.fi-main')

    if (!main) {
        return null
    }

    const scope = createScope()

    activeScope = scope

    // gsap.context() reverts tweens, not observers, listeners or appended nodes.
    // This nested context exists only so the runtime's revert reaches the
    // disposer below.
    gsap.context(() => () => {
        if (activeScope === scope) {
            activeScope = null
        }

        scope.dispose()
    })

    scope.add(purge)
    scope.add(dropStylesheet)

    const style = document.createElement('style')

    style.id = STYLE_ID
    style.textContent = CSS
    document.head.appendChild(style)

    /* ------------------------------------------------------------------ *
     * Capability
     * ------------------------------------------------------------------ */

    let rank = RANK[window.__vfMotion?.tier ?? 'medium'] ?? RANK.medium

    const wide = window.matchMedia(WIDE)
    const rtl = document.dir === 'rtl'

    /** Nothing here is continuous, so a demotion has nothing to switch off --
     *  but it must not leave a half-drawn bar behind either. */
    const settleAll = () => {
        const fills = document.querySelectorAll('.vf-spark-fill')

        if (fills.length) {
            gsap.killTweensOf(fills)
            gsap.set(fills, { clearProps: 'transform,transformOrigin,willChange' })
        }

        const cards = document.querySelectorAll('.vf-spark-card')

        if (cards.length) {
            gsap.killTweensOf(cards)
            gsap.set(cards, { clearProps: 'clipPath,opacity,willChange' })
        }
    }

    scope.on(document, 'vf:motion-tier', (event) => {
        const next = event && event.detail ? event.detail.tier : null

        if (typeof next !== 'string' || !(next in RANK)) {
            return
        }

        rank = RANK[next]

        if (rank === RANK.low) {
            settleAll()
        }
    })

    /* ------------------------------------------------------------------ *
     * The bandwidth series
     *
     * Read once from the markup, then kept current by the event Filament
     * publishes whenever the chart's own data changes. `updateChartData` is a
     * Livewire dispatch, which reaches the DOM as a bubbling CustomEvent from
     * the widget's root element -- the same signal Filament's chart component
     * listens to, so the sparkline and the chart can never disagree.
     * ------------------------------------------------------------------ */

    let series = null
    let seriesLabel = ''

    const chartFrame = () => main.querySelector('.fi-wi-chart .fi-wi-chart-frame[x-data]')

    const chartHeading = () => {
        const widget = main.querySelector('.fi-wi-chart')
        const heading = widget ? widget.querySelector('.fi-section-header-heading') : null

        return heading ? heading.textContent.trim() : ''
    }

    const adoptSeries = (payload) => {
        const read = readSeries(payload)

        if (!read) {
            return false
        }

        series = read.values
        seriesLabel = [chartHeading(), read.label].filter(Boolean).join(' · ')

        return true
    }

    const readInitialSeries = () => {
        if (series) {
            return
        }

        const frame = chartFrame()

        if (!frame) {
            return
        }

        const attr = frame.getAttribute('x-data')

        if (!attr) {
            return
        }

        adoptSeries(extractCachedData(attr))
    }

    /* ------------------------------------------------------------------ *
     * Build
     * ------------------------------------------------------------------ */

    /* The first build that actually produces something gets the draw-in; every
       later one -- a 30s table poll, a sort, a page change -- rebuilds silently,
       because a table that re-animates twice a minute is a table nobody can
       read. Tracked per family and only once something exists, because the two
       arrive independently: widgets are lazy and a table can be morphed in after
       the page has settled, and an empty first pass must not spend the animation
       on nothing. */
    let stagedBars = false
    let stagedCard = false

    const viewportHeight = () => window.innerHeight || document.documentElement.clientHeight || 0

    /** Whether an element is close enough to the viewport to be worth animating.
     *  A bar off screen is simply drawn at its true width -- there is no state
     *  it could be stuck in. */
    const onScreen = (element) => {
        const height = viewportHeight()

        if (!height) {
            return false
        }

        const rect = element.getBoundingClientRect()

        return rect.height > 0 && rect.bottom > 0 && rect.top < height
    }

    const buildBars = () => {
        if (!wide.matches) {
            return []
        }

        const tables = main.querySelectorAll('.fi-ta-table')

        if (!tables.length) {
            return []
        }

        const budget = MAX_BARS[rank] ?? MAX_BARS[RANK.medium]
        const built = []

        // Read every table first, then write. One layout pass for the page
        // rather than one per column.
        const plans = []

        tables.forEach((table) => {
            readTable(table).forEach((cells) => {
                const planned = planColumn(cells)

                if (planned.length) {
                    plans.push({ table, planned })
                }
            })
        })

        plans.forEach(({ table, planned }) => {
            planned.forEach((plan) => {
                if (built.length >= budget || !plan.host.isConnected) {
                    return
                }

                plan.host.classList.add(HOST_CLASS)

                const bar = buildBar(plan)

                plan.host.appendChild(bar)
                built.push({ table, fill: bar.firstElementChild })
            })
        })

        return built
    }

    const buildCard = () => {
        if (!series) {
            return null
        }

        const plot = plotSeries(series)

        if (!plot) {
            return null
        }

        const cards = main.querySelectorAll('.fi-wi-stats-overview-stat')

        for (const card of cards) {
            // Filament's own stat chart owns this strip when a stat declares
            // one; two lines in the same 24px would be unreadable.
            if (card.querySelector('.fi-wi-stats-overview-stat-chart')) {
                continue
            }

            const value = card.querySelector('.fi-wi-stats-overview-stat-value')

            // The bandwidth card is the one whose headline is a byte quantity.
            // Matching on the FORMAT rather than on a label keeps this working
            // in every locale the panel ships.
            if (!value || parseBytes(value.textContent) === null) {
                continue
            }

            const wrapper = document.createElement('div')

            wrapper.className = 'vf-spark-card'
            wrapper.setAttribute(OWNED, 'card')
            wrapper.setAttribute('aria-hidden', 'true')

            if (seriesLabel) {
                // Every word of this came from the server, already localised:
                // the chart's heading, its dataset labels and its first and last
                // bucket labels. The line says what it is without this file
                // inventing a single string of copy.
                wrapper.title = seriesLabel
            }

            const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg')

            svg.setAttribute('viewBox', '0 0 100 24')
            svg.setAttribute('preserveAspectRatio', 'none')
            svg.setAttribute('focusable', 'false')

            const area = document.createElementNS('http://www.w3.org/2000/svg', 'path')

            area.setAttribute('class', 'vf-spark-area')
            area.setAttribute('d', plot.area)

            const line = document.createElementNS('http://www.w3.org/2000/svg', 'polyline')

            line.setAttribute('class', 'vf-spark-line')
            line.setAttribute('points', plot.line)
            // Without this the non-uniform viewBox stretch would make the stroke
            // several times thicker at one end of the card than the other.
            line.setAttribute('vector-effect', 'non-scaling-stroke')

            svg.appendChild(area)
            svg.appendChild(line)
            wrapper.appendChild(svg)
            card.appendChild(wrapper)

            return wrapper
        }

        return null
    }

    const draw = (bars, card) => {
        const drawBars = bars.length > 0 && !stagedBars
        const drawCard = Boolean(card) && !stagedCard

        stagedBars = stagedBars || bars.length > 0
        stagedCard = stagedCard || Boolean(card)

        if (rank === RANK.low || (!drawBars && !drawCard)) {
            return
        }

        // Group by table so a wide log and a narrow overview each read as one
        // sweep rather than as one queue spanning the page.
        const byTable = new Map()

        if (drawBars) {
            bars.forEach((bar) => {
                // A bar off screen is simply left at its true width. There is no
                // state it could be stuck in, so there is nothing to fail open.
                if (!onScreen(bar.fill)) {
                    return
                }

                const bucket = byTable.get(bar.table)

                if (bucket) {
                    bucket.push(bar.fill)
                } else {
                    byTable.set(bar.table, [bar.fill])
                }
            })
        }

        const duration = rank === RANK.high ? MOTION.slow * 0.8 : MOTION.base
        const stagger = rank === RANK.high ? MOTION.stagger : MOTION.stagger * 0.6

        byTable.forEach((fills) => {
            gsap.set(fills, {
                transformOrigin: rtl ? 'right center' : 'left center',
                willChange: 'transform',
            })

            gsap.fromTo(
                fills,
                { scaleX: 0 },
                {
                    scaleX: 1,
                    duration,
                    // No overshoot anywhere in this file: a bar that springs
                    // past its mark is a bar briefly reporting a number the
                    // server did not send.
                    ease: MOTION.ease,
                    // Bounded whatever the row count, so the last bar in a
                    // fifty-row log is not still arriving a second later.
                    stagger: { amount: Math.min(fills.length * stagger, 0.5), from: 'start' },
                    // The width is the value; the transform was only ever the
                    // animation. Handing it back leaves nothing live on the
                    // element -- which is also what keeps a cell from becoming
                    // the containing block for a modal Filament renders inside
                    // the table.
                    clearProps: 'transform,transformOrigin,willChange',
                },
            )
        })

        if (drawCard && onScreen(card)) {
            gsap.set(card, { willChange: 'clip-path' })

            gsap.fromTo(
                card,
                { clipPath: 'inset(0% 100% 0% 0%)' },
                {
                    clipPath: 'inset(0% 0% 0% 0%)',
                    duration: rank === RANK.high ? MOTION.slow : MOTION.base,
                    ease: MOTION.ease,
                    // Cleared rather than left at inset(0): a live clip-path
                    // clips hit-testing and captures fixed descendants.
                    clearProps: 'clipPath,willChange',
                },
            )
        }

        // Insurance, on the wall clock rather than on GSAP finishing. If a tween
        // is killed between frames, this is what guarantees every bar is at its
        // true width and nothing is left half drawn. The margin clears the
        // longest possible run: the tween itself plus the capped stagger.
        gsap.delayedCall(duration + 0.5 + 0.4, settleAll)
    }

    /* ------------------------------------------------------------------ *
     * Rebuild
     *
     * Livewire replaces the rows on every poll, which takes our bars with them
     * -- the right outcome, since the numbers they described have just changed.
     * The observer notices and rebuilds from the new figures.
     *
     * The rebuild is deliberately SYNCHRONOUS inside the observer callback:
     * MutationObserver callbacks run at the microtask checkpoint, before the
     * browser paints, so the bars are back in place in the same frame the morph
     * landed and nothing flickers. (`livewire:update`, which two other modules
     * listen for, is a Livewire v2 event and never fires on v3 -- verified
     * against vendor/livewire/livewire/dist/livewire.js, which dispatches only
     * init / initializing / initialized / navigate / navigating / navigated.)
     * ------------------------------------------------------------------ */

    let observer = null
    let frameBuilds = 0
    let frame = 0
    let pending = false

    const build = () => {
        if (scope.disposed) {
            return
        }

        purge()
        readInitialSeries()

        const bars = buildBars()
        const card = buildCard()

        draw(bars, card)
    }

    const sync = () => {
        if (scope.disposed) {
            return
        }

        // A background tab still receives Livewire's polls. There is nothing to
        // see there, so the work is deferred until someone is looking.
        if (document.hidden) {
            pending = true

            return
        }

        if (frameBuilds >= MAX_SYNC_REBUILDS) {
            pending = true
            schedule()

            return
        }

        frameBuilds += 1
        pending = false

        // Our own appends are childList mutations too. Disconnecting across the
        // write is what stops the observer from re-entering on its own work;
        // disconnect() also empties the pending record queue.
        if (observer) {
            observer.disconnect()
        }

        try {
            build()
        } finally {
            if (observer && !scope.disposed) {
                observer.observe(main, { childList: true, subtree: true })
            }
        }
    }

    const schedule = () => {
        if (frame) {
            return
        }

        frame = requestAnimationFrame(() => {
            frame = 0
            frameBuilds = 0

            if (pending) {
                sync()
            }
        })
    }

    scope.add(() => {
        if (frame) {
            cancelAnimationFrame(frame)
            frame = 0
        }
    })

    if (typeof MutationObserver === 'function') {
        observer = new MutationObserver(() => {
            sync()
            schedule()
        })

        scope.add(() => {
            observer.disconnect()
            observer = null
        })
    }

    scope.on(document, 'visibilitychange', () => {
        if (document.hidden || !pending) {
            return
        }

        // A hidden tab never runs the rAF that clears this, so it is cleared by
        // hand -- otherwise the catch-up rebuild could arrive already at its
        // per-frame ceiling and be deferred again.
        frameBuilds = 0
        sync()
    })

    // The chart republishes its series on every poll and on every filter change.
    // Adopting it here is what keeps the card line from quietly ageing: the
    // widget's `x-data` sits behind `wire:ignore`, so the markup we first read it
    // from is never updated again.
    scope.on(document, 'updateChartData', (event) => {
        const payload = event && event.detail ? event.detail.data : null

        if (!adoptSeries(payload)) {
            return
        }

        sync()

        // The refreshed line replaces the old one immediately -- correctness is
        // not deferred to an animation -- and only then acknowledges the update.
        if (rank === RANK.high && !document.hidden) {
            const card = main.querySelector('.vf-spark-card')

            if (card && onScreen(card)) {
                gsap.fromTo(
                    card,
                    { opacity: 0.35 },
                    {
                        opacity: 1,
                        duration: MOTION.fast,
                        ease: MOTION.easeSoft,
                        clearProps: 'opacity',
                    },
                )
            }
        }
    })

    // Crossing the breakpoint rebuilds: below it the bars are removed rather
    // than left sitting in a stacked layout that has no room for them.
    scope.on(wide, 'change', () => sync())

    sync()

    return null
}

/*
 * WHAT WOULD BE NEEDED FOR A REAL PER-ROW TREND
 * ---------------------------------------------
 * The samples exist -- `bandwidth_samples` is written per user by the poller and
 * already carries `service_user_id`. What is missing is a way for the row to
 * reach the browser with them. The smallest honest change is a column on
 * ServiceUsersTable that renders the last N buckets for the record into a data
 * attribute (one grouped query for the whole page, keyed by user, not one per
 * row), at which point `plotSeries()` above draws it unchanged. Until that
 * exists, a per-row trend line here would be a drawing of nothing.
 */
