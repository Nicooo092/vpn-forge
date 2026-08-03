/**
 * Live telemetry -- the poll made visible.
 * ----------------------------------------
 * Every page in this panel watches something that moves: peers hand shake,
 * byte counters climb, a service flips from running to stopped. Filament polls
 * for that on a 30-60s timer and morphs the new values straight into the DOM,
 * which means the single most important event in the whole product -- new data
 * arriving -- currently happens in complete silence. An operator who looked away
 * has no way to tell which number is new.
 *
 * This module is the answer. It remembers what every value-bearing cell said,
 * and when a Livewire update changes one, that cell -- and only that cell --
 * lights up: a tint that decays behind the text, a status badge that pops, and
 * on the headline stats an odometer that rolls the old reading up to the new one.
 *
 * Five rules keep it honest on a screen people run a network from.
 *
 * 1. CHANGE, NOT REDRAW. Nothing animates because a poll happened; things
 *    animate because their text differs from what it was. A poll that returns
 *    identical data is invisible, which is correct -- nothing happened.
 *
 * 2. NEVER THE WHOLE TABLE. A sort, a filter or a page change rewrites every
 *    cell at once. That is navigation, not telemetry, so a burst wider than
 *    BURST_RATIO is recorded silently, and no single update may animate more
 *    than MAX_EFFECTS cells whatever else it did.
 *
 * 3. NOT ON ARRIVAL. An element is snapshotted the first time it is seen and is
 *    never animated on that pass, and the first WARMUP seconds of a page are
 *    recorded silently -- that second belongs to the entrance sequence.
 *
 * 4. SHORT. The flash is a single tween, 620ms end to end. On a 30s poll the
 *    screen is busy for two per cent of the cycle.
 *
 * 5. READABLE THROUGHOUT. Nothing in here touches opacity: the flash is a tint
 *    painted behind the text, the pulse is a transform. Table cells are never
 *    moved at all -- shifting a value someone is reading out of a row is exactly
 *    the thing motion.css refuses to do on hover, and a poll is a worse moment
 *    for it. The one effect that takes ownership of an element's TEXT, the
 *    odometer, writes the true value back from four independent paths, one of
 *    which is a wall-clock timer that does not depend on GSAP running at all.
 *
 * Where the update comes from: Livewire's `morphed` hook, which fires once per
 * component the moment its DOM has been patched, and hands over that component's
 * root element so the scan is scoped to the thing that actually changed. Note
 * that `livewire:update` -- which the runtime also listens for -- is NOT an event
 * this Livewire dispatches (its DOM events are init/initializing/initialized and
 * the three navigate ones), so it is not relied on here.
 *
 * Livewire hooks cannot be unregistered. They are therefore bound exactly once,
 * at module scope, and forward to whichever run is currently `active`; the
 * runtime's teardown sets that to null and the hook becomes a no-op until the
 * next boot installs its replacement.
 */

import { CustomEase } from 'gsap/CustomEase'

/* Marks an element currently carrying the highlight. Also how a run that was
   interrupted mid-flash gets swept up by the next one. */
const FLASH = 'data-vf-live-flash'

const STYLE_ID = 'vf-live'

/*
 * counters.js's contract for "a tween currently owns this element's text".
 * Shared on purpose rather than duplicated under another name: two modules
 * writing the same textContent is the one bug in this whole layer that could put
 * a wrong number in front of an operator, and these two attributes are how each
 * knows to keep its hands off the other's. It also buys a second failsafe -- the
 * heal pass at the top of counters.js restores anything either module left
 * mid-count, from either module's remembered value.
 */
const IN_FLIGHT = 'data-vf-counting'
const ORIGINAL = 'data-vf-count-from'

/* Seconds of silence after a boot. The entrance sequence, the counters' first
   count-up and any lazy widget all land inside this window; none of that is a
   value changing, and all of it would otherwise read as one. */
const WARMUP = 1.6

/* Hard ceiling on cells animated by a single update, and the share of scanned
   cells above which an update is treated as a redraw rather than as news. */
const MAX_EFFECTS = 12
const BURST_RATIO = 0.4
const BURST_FLOOR = 6

/* Ceiling on how many elements one scan will look at. A table page can carry a
   few hundred cells; past this it is cheaper to miss a flash than to walk the
   DOM on every poll. */
const MAX_CANDIDATES = 600

const FLASH_DURATION = 0.62
const ROLL_DURATION = 0.55

/* The whole character of the highlight lives in this curve, which is why it is
   worth a plugin: the value rises to full in the first eighth of the tween and
   then decays away like phosphor, fast at first and with a long tail. Because
   the path ends at y=0 the tween finishes back where it started, so one
   `fromTo` is the entire attack-and-decay -- no timeline, nothing to leave
   half-played. CustomEase only re-scales a path whose X range is not 0..1, so a
   Y that returns to zero is passed through untouched. */
const FLASH_EASE = 'vf-live-flash'
const FLASH_EASE_PATH = 'M0,0 C0.03,0.9 0.07,1 0.12,1 C0.3,1 0.45,0.05 1,0'

/* Value carriers, in the order they are classified. Deliberately the innermost
   thing that holds a reading: a stat's value div, a table cell, an infolist
   text item, a badge. Every one of these was checked against
   vendor/filament/**\/*.blade.php and the embedded-view columns in
   vendor/filament/tables/src/Columns. */
const CANDIDATE_PARTS = [
    /* stat.blade.php:34 and :43 */
    '.fi-wi-stats-overview-stat-value',
    '.fi-wi-stats-overview-stat-description',
    /* tables/src/Columns/Column.php:200, one <td> per column with a wire:key of
       its own (tables/resources/views/index.blade.php:2339), which is what makes
       a cell's identity survive a morph, a re-sort and a poll. */
    '.fi-ta-cell',
    /* A column footer total -- the sum of the bytes column, the count of live
       peers. Summarizer.php:213. Its cell carries no `.fi-ta-col`, so it is the
       summary itself that has to be the candidate. */
    '.fi-ta-text-summary',
    /* infolists/src/Components/TextEntry.php:315 */
    '.fi-in-text-item',
    /* support/resources/views/components/badge.blade.php, and the inline badge
       TextColumn.php:232 emits inside a cell. */
    '.fi-badge',
    /* Opt-in for this panel's own Blade views, which are not Filament markup. */
    '.vf-live-value',
]

const CANDIDATES = CANDIDATE_PARTS.join(', ')

/*
 * Numeric parsing, sharing counters.js's rules so the two modules cannot
 * disagree about what a number looks like on the same stat. Guilty until proven
 * innocent: a parse is only accepted if re-rendering it reproduces the original
 * string character for character, so versions, dates and anything with an
 * ambiguous separator are refused rather than guessed at.
 *
 * One rule of counters.js's is deliberately absent: it refuses a number whose
 * unit is a time word, because counting "3 days" up FROM ZERO reads as a clock
 * running. Here there is no zero -- a roll goes from the reading that was on
 * screen to the one that replaced it, so "2 days" becoming "3 days" ticks once
 * and says exactly what happened.
 */

/* Comma from PHP's number_format, the three space variants locale-aware
   formatters emit, dot for the decimal. */
const SEPARATOR = /[.,   ]/
const TOKEN = /[+-]?\d+(?:[.,   ]\d+)*/
/* A number followed by one of these and another digit is a date, a time or a
   version, never a quantity. */
const STRUCTURED = /^[-/:.,]\s*\d/
const VERSION = /^\d+\.\d+\.\d+/
/* Prefixes are for symbols. A letter in front of the number means the number is
   embedded in prose and the string is not a reading. */
const SYMBOL_ONLY = /^[^\p{L}\p{N}]{0,4}$/u

const CSS = `
/* Registered so the value is typed: an unregistered custom property still
   substitutes correctly (GSAP writes it every frame either way), but a
   registered one interpolates and reads back as a number. */
@property --vf-live-flash { syntax: '<number>'; inherits: false; initial-value: 0; }

/* The colour is not chosen here. --vf-flash-bg is motion.css's token for
   exactly this -- a value that just changed -- and it already has its own dark
   mode value; all this rule does is turn the number GSAP owns into that tint's
   alpha. The literal fallback is only reached if motion.css is ever missing. */
[${FLASH}] {
    --vf-live-tint: color-mix(
        in oklab,
        var(--vf-flash-bg, color-mix(in oklab, var(--primary-500, #f59e0b) 22%, transparent))
            calc(var(--vf-live-flash) * 100%),
        transparent
    );

    border-radius: 0.3rem;
    background-color: var(--vf-live-tint);
    /* Spread, not padding: the highlight needs room around the text and padding
       would move the text. A shadow paints outside the border box and costs
       nothing in layout. */
    box-shadow: 0 0 0 0.3rem var(--vf-live-tint);
}
`

/* Bound once for the life of the document -- Livewire has no way to remove a
   hook, so registering per boot would leave one dead closure per navigation. */
let hooksBound = false

/* The run the hooks currently forward to, or null between a teardown and the
   next boot. Module scope, because it has to outlive the gsap.context that the
   runtime reverts. */
let active = null

/**
 * @param {{gsap: import('gsap').gsap, ScrollTrigger: object, MOTION: object}} api
 */
export function liveData({ gsap, MOTION }) {
    if (typeof document === 'undefined' || !document.body) {
        return null
    }

    gsap.registerPlugin(CustomEase)
    CustomEase.create(FLASH_EASE, FLASH_EASE_PATH)

    const main = document.querySelector('.fi-main')

    if (!main) {
        return null
    }

    /*
     * gsap.context() with no argument hands back the context we are running
     * inside -- the runtime's. Every tween below is created later, from a
     * Livewire hook, and would otherwise be born with no context at all: nothing
     * would revert it and the inline custom property it writes would survive the
     * next navigation frozen mid-decay. Routing them through add() puts them
     * back under the runtime's control. Lifted from interactions.js, including
     * the reason the result is fished out through a closure: context.add()
     * treats a returned function as a cleanup callback.
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

    /* Previous reading per element. Weak because a table drops and re-creates
       rows all day: an entry has to disappear with the node it describes. */
    const seen = new WeakMap()

    /* Elements whose text a roll currently owns. A real Map, not weak -- this is
       the list teardown has to walk to write the true values back. Entries are
       deleted the moment a roll settles, so it holds at most a handful. */
    const rolling = new Map()

    /* The live flash tween per element, so a cell that changes twice inside one
       decay does not have the second tween's cleanup fire against the first. */
    const flashes = new WeakMap()

    /* Elements currently carrying the flash attribute, for teardown. */
    const lit = new Set()

    const startedAt = performance.now()
    let alive = true

    /* ------------------------------------------------------------------ *
     * Teardown
     *
     * gsap.context reverts tweens; it cannot remove a style tag, an attribute
     * or a pending setTimeout, and it has no idea this module rewrote anyone's
     * textContent. A nested context exists purely so the runtime's revert
     * reaches the disposer below.
     * ------------------------------------------------------------------ */

    /* Anything a previous run was interrupted mid-flash is swept by attribute,
       in case that run never got to dispose. Text left mid-ROLL is deliberately
       NOT swept here: counters.js may legitimately be counting an element up at
       this very moment (its first-view tween starts during its own module run,
       which is a few lines before ours), and healing that would freeze its
       number. The wall-clock failsafe on each roll is what covers the case this
       cannot. */
    document.querySelectorAll(`[${FLASH}]`).forEach((element) => {
        element.removeAttribute(FLASH)
        element.style.removeProperty('--vf-live-flash')
    })

    const stale = document.getElementById(STYLE_ID)

    if (stale) {
        stale.remove()
    }

    const style = document.createElement('style')
    style.id = STYLE_ID
    style.textContent = CSS
    document.head.appendChild(style)

    const dispose = () => {
        alive = false

        if (active === run) {
            active = null
        }

        /* Copied out first: settling deletes from the map it is iterating. */
        Array.from(rolling.values()).forEach((entry) => {
            window.clearTimeout(entry.timer)
            entry.settle()
        })
        rolling.clear()

        lit.forEach((element) => {
            element.removeAttribute(FLASH)
            element.style.removeProperty('--vf-live-flash')
        })
        lit.clear()

        style.remove()
    }

    gsap.context(() => () => dispose())

    /* ------------------------------------------------------------------ *
     * Reading a cell
     * ------------------------------------------------------------------ */

    /* Blade's indentation moves with the markup around a value, not with the
       value, so it cannot be allowed to read as a change. */
    const collapse = (text) => text.replace(/\s+/g, ' ').trim()

    /**
     * What an element currently says, reduced to something two polls apart can
     * be compared with. Whitespace is collapsed because Blade's indentation
     * changes with the markup around it, not with the data.
     *
     * @param {HTMLElement} element
     * @returns {string}
     */
    function signature(element) {
        /* Another module's tween may own this element's text right now --
           counters.js counts a stat up from zero the first time it is seen, and
           it starts doing so a few lines before this module even runs. While it
           does, the DOM says something that was never a reading and the
           attribute holds the truth.

           Our OWN roll is the exception: this is only ever called from a boot or
           from `morphed`, and at `morphed` Livewire has just written the
           server's current answer over whatever our tween had put there, so
           there the DOM is authoritative and the attribute is the stale one. */
        if (!rolling.has(element)) {
            const remembered = element.getAttribute(ORIGINAL)

            if (remembered !== null) {
                return collapse(remembered)
            }
        }

        const text = collapse(element.textContent)

        if (text !== '') {
            return text
        }

        /* An icon column carries no text at all, so a boolean or status flip
           would be invisible to a text diff. Its identity is the icon's colour
           classes plus the head of its path data -- both plain attribute reads,
           so this costs no layout, and it only runs on the handful of cells that
           have nothing to say otherwise. */
        const icons = element.querySelectorAll('.fi-icon')

        if (!icons.length) {
            return ''
        }

        let marks = ''

        for (let i = 0; i < icons.length && i < 3; i += 1) {
            const icon = icons[i]
            const path = icon.matches('svg') ? icon.querySelector('path') : icon.querySelector('svg path')

            marks += `${icon.getAttribute('class') || ''}|${(path && path.getAttribute('d')) || ''}`.slice(0, 96)
        }

        return marks
    }

    /* ------------------------------------------------------------------ *
     * Effects
     * ------------------------------------------------------------------ */

    function release(element, tween) {
        if (flashes.get(element) !== tween) {
            return
        }

        flashes.delete(element)
        lit.delete(element)
        element.removeAttribute(FLASH)
        element.style.removeProperty('--vf-live-flash')
    }

    /**
     * The highlight: a tint that rises behind the value and decays. Background
     * and box-shadow only -- the text does not move, does not fade and is
     * readable at every frame of it.
     */
    function flash(element) {
        if (!element || !element.isConnected) {
            return
        }

        const tween = owned(() =>
            gsap.fromTo(
                element,
                { '--vf-live-flash': 0 },
                {
                    '--vf-live-flash': 1,
                    duration: FLASH_DURATION,
                    ease: FLASH_EASE,
                    overwrite: 'auto',
                },
            ),
        )

        if (!tween) {
            return
        }

        /* Marked AFTER the tween exists, and the callbacks attached after that.
           A cell can change again while its last flash is still decaying, and
           `overwrite` kills the outgoing tween -- whose cleanup would strip the
           attribute this one needs. Ordered this way the outgoing release
           happens first and is simply redone below; and if it lands a tick later
           instead, `release` finds the entry already replaced and stands down. */
        flashes.set(element, tween)
        lit.add(element)
        element.setAttribute(FLASH, '')

        const finish = () => release(element, tween)

        tween.eventCallback('onComplete', finish)
        tween.eventCallback('onInterrupt', finish)
    }

    /**
     * A status badge popping as it takes its new colour. Transform only, so
     * nothing around it moves, and Filament's own colour transition on
     * `.fi-badge` runs underneath it untouched -- motion.css transitions the
     * individual `scale` property, GSAP writes `transform`, and the two compose.
     */
    function pulse(badge) {
        if (!badge || !badge.isConnected) {
            return
        }

        owned(() =>
            gsap.fromTo(
                badge,
                { scale: 1 },
                {
                    scale: 1.14,
                    duration: 0.16,
                    ease: MOTION.easeSoft,
                    yoyo: true,
                    repeat: 1,
                    overwrite: 'auto',
                    transformOrigin: '50% 50%',
                    clearProps: 'transform,transformOrigin,willChange',
                },
            ),
        )
    }

    /**
     * The new reading arriving from the direction it moved: a number that went
     * up rises into place, one that fell drops into it, through a short defocus
     * that resolves as it lands. Opacity is deliberately left alone -- the value
     * is legible in every frame, blurred for a third of a second at worst.
     *
     * Both properties are cleared when the tween lands. A live `filter` or
     * `transform` would make the element the containing block for any
     * fixed-position descendant, and while a stat value has none today, leaving
     * one behind for the life of the page to save one clearProps is not a trade
     * worth making in this panel.
     */
    function land(element, direction) {
        owned(() => {
            gsap.set(element, { willChange: 'transform, filter' })

            return gsap.fromTo(
                element,
                { y: direction * 9, filter: 'blur(2.5px)' },
                {
                    y: 0,
                    filter: 'blur(0px)',
                    duration: 0.42,
                    ease: MOTION.ease,
                    overwrite: 'auto',
                    clearProps: 'transform,filter,willChange',
                },
            )
        })
    }

    /* ------------------------------------------------------------------ *
     * The odometer
     * ------------------------------------------------------------------ */

    function stopRoll(element) {
        const entry = rolling.get(element)

        if (!entry) {
            return
        }

        window.clearTimeout(entry.timer)
        entry.tween.kill()
        entry.settle()
    }

    /**
     * Roll one stat's text from the reading it used to show to the one it shows
     * now. Refuses everything it cannot reproduce exactly, and refuses outright
     * if anything else already owns this element's text.
     *
     * @returns {number} the direction of travel, or 0 if nothing was rolled.
     */
    function roll(element, fromText, toText) {
        /* textContent is rewritten wholesale, so an element with child markup is
           out of scope -- writing to it would delete the markup. */
        if (element.children.length > 0) {
            return 0
        }

        if (element.hasAttribute(IN_FLIGHT) && !rolling.has(element)) {
            return 0
        }

        /* Read before anything below can write. `stopRoll` settles a roll that
           is still running by putting ITS remembered string back, so a read
           taken after it would capture a reading two polls old and then hand it
           back as the final value. */
        const raw = element.textContent

        const from = describe(fromText)
        const to = describe(toText)

        if (!from || !to || from.value === to.value) {
            return 0
        }

        /* The two readings have to be the same KIND of thing. A stat that went
           from "1.9 GB" to "412 MB" is not a number that changed, it is a
           different number, and interpolating between them would render several
           frames of a quantity the server never sent. */
        if (
            from.prefix !== to.prefix ||
            from.suffix !== to.suffix ||
            from.group !== to.group ||
            from.point !== to.point ||
            from.decimals !== to.decimals
        ) {
            return 0
        }

        stopRoll(element)

        const direction = to.value > from.value ? 1 : -1

        element.setAttribute(ORIGINAL, raw)
        element.setAttribute(IN_FLIGHT, '')

        let settled = false

        const settle = () => {
            if (settled) {
                return
            }

            settled = true
            rolling.delete(element)

            if (element.isConnected) {
                /* The raw string, not a re-render of it: this puts back the
                   whitespace exactly as Blade emitted it. */
                element.textContent = raw
                element.removeAttribute(IN_FLIGHT)
                element.removeAttribute(ORIGINAL)
            }
        }

        const state = { value: from.value }

        const tween = owned(() =>
            gsap.to(state, {
                value: to.value,
                duration: ROLL_DURATION,
                /* power3.out rather than the panel's expo: expo puts almost all
                   of the movement in the first third, which on a number reads as
                   a snap followed by stillness. */
                ease: MOTION.easeSoft,
                onUpdate() {
                    element.textContent = render(state.value, to)
                },
                onComplete: settle,
                onInterrupt: settle,
            }),
        )

        if (!tween) {
            settle()

            return 0
        }

        /* The number is the only thing on screen this module makes briefly
           wrong, so its correctness cannot rest on GSAP finishing. This runs on
           the wall clock regardless and no-ops if the tween already settled. */
        const timer = window.setTimeout(settle, ROLL_DURATION * 1000 + 400)

        rolling.set(element, { settle, tween, timer })

        return direction
    }

    /* ------------------------------------------------------------------ *
     * The scan
     * ------------------------------------------------------------------ */

    /**
     * The value carriers inside `root` that are worth watching.
     */
    function collect(root) {
        const raw = []

        if (root.matches && root.matches(CANDIDATES)) {
            raw.push(root)
        }

        const inside = root.querySelectorAll(CANDIDATES)

        for (let i = 0; i < inside.length && raw.length < MAX_CANDIDATES; i += 1) {
            raw.push(inside[i])
        }

        if (!raw.length) {
            return []
        }

        /* Disqualify BEFORE the nesting pass, not after: a summary cell is a
           `.fi-ta-cell` that reports nothing itself, and if it were still in the
           set when nesting is resolved it would swallow the `.fi-ta-text-summary`
           inside it -- the one element there that does report something. */
        const found = raw.filter((element) => {
            /* Selection and action cells are `.fi-ta-cell` too. Only a real
               column wraps its content in `.fi-ta-col` (Column.php:175), and a
               checkbox or a row of buttons has no reading to report. */
            if (element.matches('.fi-ta-cell')) {
                return Boolean(element.querySelector('.fi-ta-col'))
            }

            return true
        })

        const known = new Set(found)

        /* Outermost only. A badge inside a table cell is dropped here: the cell
           already covers it, and tinting both would announce one change twice. */
        return found.filter((element) => {
            const parent = element.parentElement
            const ancestor = parent ? parent.closest(CANDIDATES) : null

            return !(ancestor && known.has(ancestor))
        })
    }

    /**
     * Compare `root` against what it last said, and light up what moved.
     *
     * @param {HTMLElement} root
     * @param {boolean} silent  record only -- the boot pass, and the warmup.
     */
    function inspect(root, silent) {
        if (!alive || !root || !root.isConnected) {
            return
        }

        const candidates = collect(root)

        if (!candidates.length) {
            return
        }

        const quiet = silent || performance.now() - startedAt < WARMUP * 1000
        const changed = []

        /* One read pass. textContent and getAttribute force no layout, so the
           whole scan is free of the thing that would make it expensive. */
        for (let i = 0; i < candidates.length; i += 1) {
            const element = candidates[i]
            const now = signature(element)
            const before = seen.get(element)

            seen.set(element, now)

            /* An element seen for the first time is recorded, never animated:
               that is what stops a lazy widget, a freshly rendered row or a
               re-opened modal from arriving in a shower of highlights. */
            if (before === undefined || before === now) {
                continue
            }

            changed.push({ element, before, now })
        }

        if (quiet || !changed.length) {
            return
        }

        /* A change this wide is a re-sort, a filter or a new page of records --
           the same cells describing different rows. Recorded, not announced. */
        if (changed.length > BURST_FLOOR && changed.length > candidates.length * BURST_RATIO) {
            return
        }

        const budget = Math.min(changed.length, MAX_EFFECTS)

        for (let i = 0; i < budget; i += 1) {
            apply(changed[i])
        }
    }

    /**
     * One changed cell, given the treatment its kind deserves.
     */
    function apply({ element, before, now }) {
        if (!element.isConnected) {
            return
        }

        /* --- table cell ---------------------------------------------------
           The tint goes on `.fi-ta-col`, the column's own wrapper, rather than
           on the <td>: the first cell of every row already carries an inset
           box-shadow from motion.css's hover rail and a pseudo-element from
           interactions.js, and the wrapper is the one surface in a row that
           nothing else has claimed. Nothing here moves -- see rule five. */
        if (element.matches('.fi-ta-cell')) {
            flash(element.querySelector('.fi-ta-col') || element)
            pulse(element.querySelector('.fi-badge'))

            return
        }

        /* --- badge ---------------------------------------------------------
           No tint: a badge's background IS its meaning, and painting over it
           would say something the data does not. Filament has already swapped
           its colour by the time this runs; the pop is what makes you look. */
        if (element.matches('.fi-badge')) {
            pulse(element)

            return
        }

        /* --- headline value ------------------------------------------------ */
        if (element.matches('.fi-wi-stats-overview-stat-value, .vf-live-value')) {
            flash(element)

            const direction = roll(element, before, now)

            if (direction !== 0) {
                land(element, direction)
            }

            return
        }

        flash(element)
    }

    /* ------------------------------------------------------------------ *
     * Wiring
     * ------------------------------------------------------------------ */

    /**
     * Narrow a component root to the part of the page this module is allowed to
     * watch. A page-level component contains the whole chrome, and the sidebar's
     * polling badges belong to the navigation module, not to this one.
     */
    function scopeOf(element) {
        if (!element || !element.isConnected || !main.isConnected) {
            return null
        }

        if (main.contains(element)) {
            return element
        }

        if (element.contains(main)) {
            return main
        }

        return null
    }

    const run = {
        notify(element) {
            const scope = scopeOf(element)

            if (scope) {
                inspect(scope, false)
            }
        },
    }

    active = run

    /* The baseline. Taken now, silently, so the first poll after a page loads
       already has something to compare against -- without it the panel would
       have to be polled twice before it could tell anyone anything. */
    inspect(main, true)

    bindHooks()

    return null
}

/**
 * Register the Livewire hooks. Once, for the life of the document: Livewire
 * offers no way to remove a hook, so a per-boot registration would leave one
 * dead closure behind on every navigation for the rest of the session.
 */
function bindHooks() {
    if (hooksBound) {
        return
    }

    hooksBound = true

    const bind = () => {
        const livewire = window.Livewire

        if (!livewire || typeof livewire.hook !== 'function') {
            return
        }

        /*
         * `morphed` fires once per component, synchronously, immediately after
         * its DOM has been patched -- which is the one instant where the text on
         * screen is guaranteed to be the server's current answer and no tween of
         * ours has had a frame in which to overwrite it. Anything deferred to a
         * rAF would be reading its own animation back.
         */
        livewire.hook('morphed', ({ el }) => {
            if (active) {
                active.notify(el)
            }
        })
    }

    if (window.Livewire && typeof window.Livewire.hook === 'function') {
        bind()

        return
    }

    /* Livewire and this runtime both boot off DOMContentLoaded and the order is
       not ours to decide. `livewire:init` is dispatched with window.Livewire
       already in place. */
    document.addEventListener('livewire:init', bind, { once: true })
}

/* ------------------------------------------------------------------------- */
/* Numeric parsing                                                            */
/* ------------------------------------------------------------------------- */

/**
 * Take a formatted reading apart into something that can be re-rendered at any
 * intermediate value. Returns null for anything it is not certain about --
 * which, on a panel that formats "0.08", "13%", "2 / 2", "1.9 GB", "2d 0h" and
 * "8.3.33" through the same code path, is most things.
 *
 * @param {string} raw
 * @returns {?{value: number, prefix: string, suffix: string, sign: string, decimals: number, group: string, point: string}}
 */
function describe(raw) {
    if (typeof raw !== 'string') {
        return null
    }

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

    /* A prefix ending in a separator means the match started mid-number. */
    if (SEPARATOR.test(prefix.slice(-1))) {
        return null
    }

    if (!SYMBOL_ONLY.test(prefix) || STRUCTURED.test(suffix)) {
        return null
    }

    const sign = token[0] === '-' || token[0] === '+' ? token[0] : ''
    const body = sign ? token.slice(1) : token
    const parts = body.split(SEPARATOR)
    const separators = body.match(new RegExp(SEPARATOR, 'g')) || []

    /* "08" is a padded field -- an hour, a minute -- not a count, and toFixed
       would quietly drop the zero. */
    if (parts[0].length > 1 && parts[0][0] === '0') {
        return null
    }

    const shape = shapeOf(parts, separators)

    if (!shape) {
        return null
    }

    const value = Number(
        `${sign === '-' ? '-' : ''}${shape.digits}${shape.fraction ? `.${shape.fraction}` : ''}`,
    )

    if (!Number.isFinite(value)) {
        return null
    }

    const format = {
        value,
        prefix,
        suffix,
        sign: sign === '+' ? '+' : '',
        decimals: shape.fraction.length,
        group: shape.group,
        point: shape.point,
    }

    /* The proof. If re-rendering the parsed value does not reproduce the string
       the server sent, character for character, the parse is wrong about
       something and this reading is left exactly as it arrived. */
    return render(value, format) === text ? format : null
}

/**
 * Classify the digit runs of a numeric token: which separator groups thousands,
 * which marks the decimal point, and what the digits are. Every shape outside
 * the small set this panel emits is refused rather than guessed at.
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
            /* "1.234" is a decimal here and a thousands group in half of Europe.
               Nothing in this panel formats to three decimal places, so refusing
               the ambiguous shape costs nothing and removes the guess. */
            if (head.length <= 3 && tail.length === 3) {
                return null
            }

            return { digits: head, fraction: tail, group: '', point: '.' }
        }

        if (leads && grouped(1)) {
            return { digits: head + tail, fraction: '', group: separators[0], point: '.' }
        }

        return null
    }

    const first = separators[0]
    const last = separators[separators.length - 1]
    const allSame = separators.every((separator) => separator === first)

    /* 1 234 567 -- every separator groups, nothing is a decimal point. */
    if (allSame && leads && parts.slice(1).every((_, index) => grouped(index + 1))) {
        return { digits: parts.join(''), fraction: '', group: first, point: '.' }
    }

    /* 1,234.56 -- all but the last separator group, and the last one has to be
       a different character from them. */
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
 * used. Rounding goes through toFixed at the original precision, so every frame
 * of a roll is formatted exactly the way its last frame will be -- there is no
 * moment where the number on screen is written differently from the number the
 * server sent.
 *
 * @param {number} value
 * @param {{prefix: string, suffix: string, sign: string, decimals: number, group: string, point: string}} format
 * @returns {string}
 */
function render(value, format) {
    const fixed = value.toFixed(format.decimals)
    const negative = fixed[0] === '-'
    const [whole, fraction] = (negative ? fixed.slice(1) : fixed).split('.')

    const digits = format.group ? whole.replace(/\B(?=(\d{3})+(?!\d))/g, format.group) : whole

    return (
        format.prefix +
        (negative ? '-' : format.sign) +
        digits +
        (fraction === undefined ? '' : format.point + fraction) +
        format.suffix
    )
}
