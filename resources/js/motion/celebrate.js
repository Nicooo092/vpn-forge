/**
 * Marking real events.
 * --------------------
 * Almost nothing in this panel is worth celebrating. It watches a live system,
 * and most of what it shows is a measurement being re-read. A few things are
 * genuinely different: a service finishing provisioning and going Active, a
 * backup completing, a user's config being generated. Those are moments where
 * the operator asked for something, went away, and came back to find it done --
 * and a panel that marks them is a panel that felt like it was working on your
 * behalf while you were gone.
 *
 * So this module fires on exactly two things, and neither of them is "a page
 * appeared":
 *
 *   1. A SUCCESS TOAST ARRIVING WHILE WE ARE WATCHING. Filament pushes
 *      notifications into the session, and a `dehydrate` hook dispatches
 *      `notificationsSent` on the next Livewire round trip, at which point the
 *      Notifications component renders a new
 *      `.fi-no-notification.fi-status-success` into `.fi-no`. A MutationObserver
 *      on that container sees the node arrive. Toasts already in the markup at
 *      boot -- the ones a redirect carried over in the session -- are recorded
 *      as seen and never celebrated, because that is the "on load" case.
 *
 *   2. A STATUS BADGE TURNING SUCCESS IN A POLLED TABLE. Not "a badge that is
 *      success" -- a badge that was something else the last time we looked and
 *      is success now. The first scan of a page only records; it never fires. A
 *      poll that re-reads an already-Active service produces an identical
 *      signature and produces nothing. Colour is the signal rather than the
 *      label, so provisioning -> Active, Error -> Active and Suspended -> Active
 *      are all caught without knowing any of those words, in any language.
 *
 * The restraint is the feature. A tool that throws a party every 30 seconds is
 * a tool people turn off, so: one celebration at a time, a cooldown behind it,
 * at most one per row per page visit (a service flapping between error and
 * active cannot chain), a small budget per page visit on top of that, nothing
 * at all while the tab is hidden or the element is off screen, and everything
 * over inside 720ms.
 *
 * What it is allowed to touch is equally narrow:
 *
 *   - NO TRANSFORMS ON STRUCTURE. Filament renders action modals inline, inside
 *     the very table row this module marks, and an element with a live
 *     transform, filter or clip-path becomes the containing block for its
 *     `position: fixed` descendants. The ring is an `outline` and the row sweep
 *     is a `background-image`; neither creates a containing block, and neither
 *     costs layout. The one transform in here is on the toast's icon, which
 *     holds no controls, and it is cleared on the way out.
 *
 *   - NOTHING IS INSERTED INTO MARKUP LIVEWIRE OWNS. The ring and the sweep are
 *     driven from JS through custom properties into a stylesheet this module
 *     injects, the same way interactions.js drives its sheens. The only node
 *     this file creates is that one <style> tag.
 *
 *   - NOTHING IS LEFT UNREADABLE. The one property here that can hide something
 *     is the stroke dash on the toast's check mark. Every celebration carries a
 *     wall-clock failsafe that restores what it touched whether or not GSAP
 *     ever finishes, and each run sweeps up anything a previous run left behind
 *     before it builds.
 */

/* Marks an element this module is currently animating, and what the next run
   sweeps for in case a run was interrupted between claiming and releasing. */
const MARK = 'data-vf-celebrate'

/* Same, for the SVG paths of a check mark mid-draw. Separate because the
   properties to undo are different -- and because a path left at full dash
   offset is an invisible icon, which is the one genuinely bad failure this file
   is capable of. */
const DRAW = 'data-vf-celebrate-draw'

const STYLE_ID = 'vf-celebrate'

/* Durations, in seconds. The ceiling is 900ms; the longest chain here lands at
   ~710ms, which leaves room for a frame budget that slips. */
const RING = 0.62
const SWEEP = 0.66
const SWEEP_OFFSET = 0.05
const DRAW_TIME = 0.5
const DRAW_OFFSET = 0.04
const ICON_TIME = 0.52

/* Wall clock, in ms. The failsafe sits far past the longest timeline on
   purpose: it exists for the case where GSAP never ticks at all. */
const FAILSAFE = 1500
const COOLDOWN = 400

/* A toast is added to the DOM by Livewire's morph and only then initialised by
   Alpine, which is what makes it visible. Reading SVG geometry before that
   returns nothing, so the celebration waits a beat -- which also reads better,
   since the check then draws as the toast lands rather than before it. */
const TOAST_SETTLE = 90

/* Batched table mutations arrive as several records inside one commit. A single
   scan once they have all landed sees a consistent table, rather than a row
   whose status cell has been morphed and whose badge has not. */
const SCAN_DELAY = 120

/*
 * How many badge transitions one page visit may mark. The per-row limit below
 * already makes sustained partying impossible, so this is only a valve for the
 * pathological case -- someone bulk-provisions forty services and watches them
 * all land. Marking the first few and then going quiet is the right behaviour
 * there anyway.
 */
const BUDGET = 6

/*
 * The current run's teardown callbacks, held at module scope so a new run can
 * undo the previous one before it builds. The runtime reverts our gsap.context,
 * which unwinds tweens but not listeners, observers, timers or an injected
 * stylesheet.
 */
let disposers = []

function drain(bag) {
    while (bag.length) {
        try {
            bag.pop()()
        } catch (error) {
            // A failing disposer must not strand the ones behind it.
            console.warn('[vpn-forge motion] celebrate cleanup failed', error)
        }
    }
}

const CSS = `
/* Registered so the values are typed: an unregistered custom property reads
   back as an empty string, which GSAP cannot interpolate and calc() cannot
   use. */
@property --vf-cel-ring { syntax: '<number>'; inherits: false; initial-value: 0; }
@property --vf-cel-fade { syntax: '<number>'; inherits: false; initial-value: 0; }
@property --vf-cel-sweep { syntax: '<number>'; inherits: false; initial-value: 0; }

/* Filament's own success ramp, emitted at :root by FilamentAsset. The literal
   fallbacks cover the frame before that inline style has been parsed. */
:root {
    --vf-cel-tint: color-mix(in oklab, var(--success-500, #22c55e) 20%, transparent);
}

.dark {
    --vf-cel-tint: color-mix(in oklab, var(--success-400, #4ade80) 15%, transparent);
}

/* --- Ring pulse ------------------------------------------------------------
   An 'outline', not a pseudo-element and not a box-shadow.

   Not a pseudo-element, because Filament badges are 'truncate' -- they clip
   their own content -- so a ring drawn inside one would be cut off at the
   badge's edge instead of expanding past it.

   Not a box-shadow, because Tailwind draws the badge's own 'ring-1' with one,
   and setting box-shadow at all replaces the whole composed stack: the badge
   would lose its border for the length of the pulse.

   An outline is painted outside the border box, is not clipped by the
   element's own overflow, costs no layout, and follows the badge's radius. The
   ring expands outward while thinning away, so there is no colour interpolation
   to get wrong -- a mis-parsed colour would fall back to currentColor and paint
   a hard opaque ring, which is exactly the failure this shape avoids.
   --color-500 is set on the element by its own .fi-color-success class, so the
   ring is always drawn in the badge's own colour.                            */
.fi-badge[${MARK}] {
    outline-style: solid;
    outline-color: color-mix(in oklab, var(--color-500, #22c55e) 65%, transparent);
    outline-width: calc(var(--vf-cel-fade) * 2.5px);
    outline-offset: calc(var(--vf-cel-ring) * 9px);
}

/* --- Row sweep -------------------------------------------------------------
   One pass of light along the row that just changed, behind the cell text.
   Nothing moves and nothing is covered: an operator reading an IP address out
   of that row must not have it shift under the cursor.

   'background-image' rather than 'background-color'. Filament transitions a
   row's colours at 75ms and motion.css adds its own at 180ms, and a
   transitioned property re-eases every value GSAP writes -- the sweep would
   smear instead of travelling. Neither transition list includes
   background-image or background-position.

   The band lives inside an image 2.6x the row's width, so moving the background
   position carries it on from one edge and off the other; at rest it is parked
   outside the box and paints nothing.

   The selector is deliberately more specific than interactions.js's
   '.fi-ta-row[data-vf-hot]' pointer glow, which is the same property on the
   same element from a stylesheet injected later. Hovering the exact row at the
   exact moment it succeeds is rare, but the outcome should be decided here
   rather than by which module happened to append its <style> last.           */
.fi-ta-table tbody .fi-ta-row[${MARK}] {
    background-image: linear-gradient(
        100deg,
        transparent 40%,
        var(--vf-cel-tint) 50%,
        transparent 60%
    );
    background-repeat: no-repeat;
    background-size: 260% 100%;
    background-position: calc(100% - var(--vf-cel-sweep) * 100%) 0;
}
`

/* ------------------------------------------------------------------------- */
/* Lifecycle helpers                                                          */
/* ------------------------------------------------------------------------- */

/**
 * A bag of teardown callbacks. Every listener, observer, timer and DOM
 * insertion in this file goes through one of these, so nothing survives either
 * a context revert or the next run.
 */
function createScope() {
    const bag = []

    /* Timers are pooled rather than each pushing its own disposer: the badge
       scanner schedules one on every table mutation, and over an hour on a
       polling page that would be several hundred dead closures held for the
       life of the page. */
    const timers = new Set()

    bag.push(() => {
        timers.forEach((id) => window.clearTimeout(id))
        timers.clear()
    })

    return {
        bag,
        on(target, type, handler, options) {
            if (!target || !target.addEventListener) {
                return
            }

            target.addEventListener(type, handler, options)
            bag.push(() => target.removeEventListener(type, handler, options))
        },
        timer(fn, delay) {
            const id = window.setTimeout(() => {
                timers.delete(id)
                fn()
            }, delay)

            timers.add(id)

            return id
        },
        add(disposer) {
            bag.push(disposer)
        },
    }
}

/**
 * Release an element the ring or the sweep was driving.
 *
 * Direct style removal rather than gsap.set(clearProps), because this also runs
 * during the runtime's revert: creating a tween while a context is mid-revert
 * leaves it running after the context has stopped tracking it. Only this
 * module's own custom properties are cleared -- nothing else on a row or a
 * badge belongs to this file.
 */
function stripDriven(element) {
    if (!element || !element.style) {
        return
    }

    element.removeAttribute(MARK)
    element.style.removeProperty('--vf-cel-ring')
    element.style.removeProperty('--vf-cel-fade')
    element.style.removeProperty('--vf-cel-sweep')
}

/** Release the toast icon, the only element here GSAP transforms directly. */
function stripTransform(element) {
    if (!element || !element.style) {
        return
    }

    element.removeAttribute(MARK)
    element.style.removeProperty('transform')
    element.style.removeProperty('transform-origin')
    element.style.removeProperty('will-change')
}

function stripDraw(path) {
    if (!path || !path.style) {
        return
    }

    path.removeAttribute(DRAW)
    path.style.removeProperty('stroke-dasharray')
    path.style.removeProperty('stroke-dashoffset')
}

/**
 * Undo anything a previous run was in the middle of. Cheap insurance: teardown
 * already releases the stage, but a run that threw between claiming an element
 * and releasing it would otherwise leave a check mark permanently invisible.
 */
function healLeftovers() {
    document.querySelectorAll(`[${MARK}]`).forEach((element) => {
        stripDriven(element)
        stripTransform(element)
    })

    document.querySelectorAll(`[${DRAW}]`).forEach(stripDraw)
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
/* The stage                                                                  */
/* ------------------------------------------------------------------------- */

/**
 * Exactly one celebration may be on screen at a time.
 *
 * Anything that wants to celebrate asks the stage for a timeline, and gets one
 * only if nothing else is running, the cooldown behind the last one has expired
 * and the tab is actually being looked at. Whatever it then builds, the `undo`
 * it handed over runs exactly once -- on completion, on abort, on teardown, or
 * on a wall-clock failsafe if GSAP never ticks at all.
 */
function createStage({ gsap, scope, owned }) {
    let current = null

    /* performance.now(), not Date.now(): a system clock correction must not be
       able to park the stage in cooldown for hours. */
    let readyAt = 0

    function open(undo) {
        if (current || document.hidden || performance.now() < readyAt) {
            return null
        }

        let released = false
        let guard = 0

        const release = () => {
            if (released) {
                return
            }

            released = true
            current = null
            readyAt = performance.now() + COOLDOWN

            if (guard) {
                window.clearTimeout(guard)
                guard = 0
            }

            try {
                undo()
            } catch (error) {
                console.warn('[vpn-forge motion] celebrate release failed', error)
            }
        }

        /* Created through the runtime's context so its revert unwinds the tween
           values; the inline properties are cleaned up by `undo` either way. */
        const timeline = owned(() => gsap.timeline({ onComplete: release }))

        if (!timeline) {
            release()

            return null
        }

        current = { timeline, release }

        /* A timeline has no onInterrupt, and this module is the one that can
           hide a check mark, so the reveal never depends on GSAP finishing. */
        guard = window.setTimeout(release, FAILSAFE)

        return timeline
    }

    function abort() {
        if (!current) {
            return
        }

        const { timeline, release } = current

        // release() first, so kill() cannot re-enter through onComplete.
        release()
        timeline.kill()
    }

    /* Nothing should be mid-pulse in a tab nobody is looking at, and a timeline
       that started before the tab was hidden would otherwise stay claimed until
       the operator came back. */
    scope.on(document, 'visibilitychange', () => {
        if (document.hidden) {
            abort()
        }
    })

    scope.add(abort)

    return { open }
}

/* ------------------------------------------------------------------------- */
/* Success toasts                                                             */
/* ------------------------------------------------------------------------- */

/**
 * A check mark drawing itself in a success notification.
 *
 * Filament's success icon is `Heroicon::OutlinedCheckCircle`: one stroked path
 * whose `d` runs the tick first and the circle second. Offsetting the dash
 * across the whole compound path therefore draws the tick and then closes the
 * circle around it, with no plugin involved -- DrawSVG is Club-only and is not
 * installed.
 *
 * The toast's own arrival is left alone. The overlays module owns that, and
 * `.fi-no-notification` carries a 300ms `transition` on all properties, so
 * anything written to the toast root would be re-eased every frame regardless.
 */
function watchToasts({ MOTION, scope, stage, lean }) {
    const container = document.querySelector('.fi-no')

    if (!container) {
        return
    }

    /* Toasts already in the markup at boot came from the session, carried over
       by a redirect. They are recorded as seen and never celebrated: whatever
       they report happened on the previous page, and marking them here would be
       celebrating a page load. Keying by `wire:key` also means a morph that
       re-creates a toast node cannot celebrate it twice. */
    const seen = new Set()

    const remember = (node) => {
        const key = node.getAttribute('wire:key')

        if (!key || seen.has(key)) {
            return false
        }

        seen.add(key)

        return true
    }

    container.querySelectorAll('.fi-no-notification').forEach(remember)

    function start(toast) {
        if (!toast.isConnected) {
            return
        }

        const icon = toast.querySelector('.fi-no-notification-icon')

        if (!icon) {
            return
        }

        // --- Read phase. Every measurement happens before the first tween.

        const box = icon.getBoundingClientRect()

        if (!box.width || !box.height) {
            return
        }

        const strokes = []

        icon.querySelectorAll('path').forEach((path) => {
            if (typeof path.getTotalLength !== 'function') {
                return
            }

            let length = 0

            try {
                length = path.getTotalLength()
            } catch (error) {
                // A path with no geometry, or one the engine refuses to measure.
                return
            }

            if (length > 0) {
                strokes.push({ path, length })
            }
        })

        // --- Write phase.

        /* A custom icon with no stroked geometry, on a tier that has already
           dropped the spring, leaves nothing to play. Claiming the stage for an
           empty timeline would only put the next real celebration behind a
           cooldown. */
        if (lean && !strokes.length) {
            return
        }

        const timeline = stage.open(() => {
            strokes.forEach(({ path }) => stripDraw(path))
            stripTransform(icon)
        })

        if (!timeline) {
            return
        }

        icon.setAttribute(MARK, '')

        /* The mark lands rather than appears. Confined to the icon: it holds no
           controls, so the transform it carries for half a second cannot
           re-anchor a fixed descendant, and it is cleared on the way out. */
        if (!lean) {
            timeline.fromTo(
                icon,
                { scale: 0.62, transformOrigin: '50% 50%' },
                {
                    scale: 1,
                    duration: ICON_TIME,
                    ease: MOTION.spring,
                    clearProps: 'transform,transformOrigin,willChange',
                },
                0,
            )
        }

        strokes.forEach(({ path, length }) => {
            path.setAttribute(DRAW, '')

            /* power2.inOut rather than the panel's expo.out: a pen has weight at
               both ends. On expo the whole stroke would be down inside the first
               sixth of the tween and the rest would be the icon sitting still. */
            timeline.fromTo(
                path,
                { strokeDasharray: length, strokeDashoffset: length },
                {
                    strokeDashoffset: 0,
                    duration: DRAW_TIME,
                    ease: 'power2.inOut',
                    clearProps: 'strokeDasharray,strokeDashoffset',
                },
                DRAW_OFFSET,
            )
        })
    }

    const observer = new MutationObserver((records) => {
        let claimed = false

        for (const record of records) {
            for (const node of record.addedNodes) {
                if (node.nodeType !== 1 || !node.classList) {
                    continue
                }

                if (!node.classList.contains('fi-no-notification')) {
                    continue
                }

                const fresh = remember(node)

                /* Only genuine success. `fi-inline` is the database-notifications
                   list, which replays stored notifications when the panel is
                   opened -- old news, arriving in bulk, and exactly what must
                   never fire. Everything else is still recorded above so a later
                   morph cannot present it as new. */
                if (
                    !fresh ||
                    claimed ||
                    !node.classList.contains('fi-status-success') ||
                    node.classList.contains('fi-inline')
                ) {
                    continue
                }

                claimed = true
                scope.timer(() => start(node), TOAST_SETTLE)
            }
        }
    })

    observer.observe(container, { childList: true })
    scope.add(() => observer.disconnect())
}

/* ------------------------------------------------------------------------- */
/* Status badges turning success                                              */
/* ------------------------------------------------------------------------- */

/** The colour a badge is wearing, or 'none' -- Filament emits no colour class
 *  at all for its default gray. */
function colourOf(badge) {
    for (const name of badge.classList) {
        if (name.startsWith('fi-color-')) {
            return name.slice(9)
        }
    }

    return 'none'
}

/**
 * A ring pulsing out of the badge that just turned green, and a sweep of light
 * along its row.
 *
 * The detection is a signature diff, not a text match: each row's badges are
 * remembered as the list of colours they were wearing, and a celebration needs
 * one of them to have been something else last time and to be success now. That
 * is what separates "this service just came up" from "this service has been up
 * for a week and the table polled again" -- and it needs to know nothing about
 * the words Provisioning, Active or Actif.
 */
function watchBadges({ MOTION, scope, stage, rich }) {
    const roots = document.querySelectorAll('.fi-ta')

    if (!roots.length) {
        return
    }

    /* Both live for exactly one page visit: the runtime rebuilds this module on
       every `livewire:navigated`, and a transition that happened while the
       operator was reading another page is not something to announce on
       arrival. */
    const seen = new Map()
    const spent = new Set()

    let budget = BUDGET

    function mark(row, badge) {
        /* Off screen, the moment has already passed. The row stays marked spent
           -- the transition was real, it just was not watched. */
        const box = badge.getBoundingClientRect()
        const fold = window.innerHeight || document.documentElement.clientHeight

        if (!box.width || box.bottom < 0 || box.top > fold) {
            return
        }

        const timeline = stage.open(() => {
            stripDriven(badge)
            stripDriven(row)
        })

        if (!timeline) {
            return
        }

        budget -= 1
        badge.setAttribute(MARK, '')

        /* Two properties, two eases, one gesture: the ring travels out and
           decelerates while its own thickness holds and then gives out, which
           reads as a pulse rather than as a circle being resized.

           power3.out rather than the panel's expo.out, for the same reason
           counters.js makes that swap: expo puts the entire 9px of travel inside
           the first fifth of the tween, so the ring would appear at its final
           radius and then sit there thinning. A ripple has to be seen to
           travel. */
        timeline
            .fromTo(
                badge,
                { '--vf-cel-ring': 0 },
                { '--vf-cel-ring': 1, duration: RING, ease: MOTION.easeSoft },
                0,
            )
            .fromTo(
                badge,
                { '--vf-cel-fade': 1 },
                { '--vf-cel-fade': 0, duration: RING, ease: 'power1.in' },
                0,
            )

        /* The sweep is the widest repaint in this file -- a full table row for
           two thirds of a second -- so it is the first thing to go when the
           governor says the machine cannot spare it. */
        if (rich) {
            row.setAttribute(MARK, '')

            timeline.fromTo(
                row,
                { '--vf-cel-sweep': 0 },
                { '--vf-cel-sweep': 1, duration: SWEEP, ease: 'power2.inOut' },
                SWEEP_OFFSET,
            )
        }
    }

    function scan() {
        let winner = null

        for (const root of roots) {
            if (!root.isConnected) {
                continue
            }

            for (const row of root.querySelectorAll('.fi-ta-table tbody .fi-ta-row')) {
                if (row.classList.contains('fi-ta-group-header-row')) {
                    continue
                }

                /* Filament keys rows by record, so this survives re-sorting,
                   re-paging and the morph that follows every poll. */
                const key = row.getAttribute('wire:key')

                if (!key) {
                    continue
                }

                const badges = row.querySelectorAll('.fi-badge')
                const colours = []

                for (const badge of badges) {
                    colours.push(colourOf(badge))
                }

                const signature = colours.join('|')
                const previous = seen.get(key)

                /* Recorded for every row on every scan, winner or not: a row
                   whose transition we decline to mark must not be able to
                   present the same transition again on the next poll. */
                seen.set(key, signature)

                if (previous === undefined || previous === signature || spent.has(key)) {
                    continue
                }

                const was = previous.split('|')

                /* A row whose badge count changed is a row whose shape changed
                   -- an action dropdown opened, a column was toggled. Positions
                   are no longer comparable, so this scan only re-seeds it. */
                if (was.length !== colours.length) {
                    continue
                }

                for (let index = 0; index < colours.length; index += 1) {
                    if (colours[index] === 'success' && was[index] !== 'success') {
                        /* Once per row per page visit. A service flapping
                           between error and active on every 10s poll would
                           otherwise celebrate forever, which is precisely the
                           behaviour that gets a panel's animations switched
                           off. */
                        spent.add(key)

                        if (!winner) {
                            winner = { row, badge: badges[index] }
                        }

                        break
                    }
                }
            }
        }

        /* Several services can land in the same poll. One is marked and the rest
           are recorded silently: five rings at once is a party, and the whole
           point of the ring is that it draws the eye to one place. */
        if (winner && budget > 0) {
            mark(winner.row, winner.badge)
        }
    }

    // The first scan only records. Nothing on a page arrives celebrated.
    scan()

    let pending = 0

    const schedule = () => {
        if (pending) {
            return
        }

        pending = scope.timer(() => {
            pending = 0
            scan()
        }, SCAN_DELAY)
    }

    /* One observer for every table on the page. `attributeFilter` keeps it
       quiet, and it is also what stops the observer feeding itself: this
       module's marks are data attributes and its writes are inline styles, so
       neither can wake it. */
    const observer = new MutationObserver(schedule)

    roots.forEach((root) =>
        observer.observe(root, {
            childList: true,
            subtree: true,
            attributes: true,
            attributeFilter: ['class'],
        }),
    )

    scope.add(() => observer.disconnect())
}

/* ------------------------------------------------------------------------- */

/**
 * @param {{gsap: import('gsap').gsap, MOTION: object}} api
 */
export function celebrate({ gsap, MOTION }) {
    // Undo the previous run before anything else.
    drain(disposers)

    if (typeof document === 'undefined' || !document.body) {
        return null
    }

    // Then sweep for anything an interrupted run left behind.
    healLeftovers()

    const capability = window.__vfMotion ?? {}

    if (capability.reduced) {
        return null
    }

    const tier = capability.tier ?? 'medium'

    /* `rich` adds the row sweep, the one effect here wide enough to matter on a
       slow box. `lean` drops the icon spring as well, leaving the check mark,
       which is a single small path and the part that carries the meaning. */
    const rich = tier === 'high'
    const lean = tier === 'low'

    const scope = createScope()

    disposers = scope.bag

    /* gsap.context() reverts tweens, not observers. This nested context exists
       so the runtime's revert reaches the disposers above; the module-level
       array covers the case where a run is replaced without one. The bag is
       captured rather than read from module scope, so reverting an old context
       can never dispose a newer run. */
    gsap.context(() => () => drain(scope.bag))

    /*
     * `gsap.context()` with no argument hands back the context we are currently
     * running inside -- the runtime's. Tweens created later, from an observer
     * callback, would otherwise be born with no context at all, and the inline
     * values they left behind would survive the next navigation.
     *
     * The result is fished out through a closure rather than returned, because
     * `context.add()` treats a returned function as a cleanup callback.
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

    injectStylesheet(scope)

    const stage = createStage({ gsap, scope, owned })

    watchToasts({ MOTION, scope, stage, lean })
    watchBadges({ MOTION, scope, stage, rich })

    return null
}
