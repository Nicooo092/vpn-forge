/*
 * Ambient background field -- the dashboard's own subject matter, drawn behind it
 * ----------------------------------------------------------------------------
 * This panel administers a network, so the one piece of pure atmosphere it gets
 * is a network: a slow constellation of nodes with distance-faded links between
 * them, and occasionally a packet travelling along one of those links. It sits
 * on a canvas behind the page content, at an alpha low enough that it reads as
 * texture in the gutters rather than as a picture.
 *
 * An ambient loop is the single easiest way to wreck a laptop battery, so the
 * whole file is organised around ONE question: is this thing allowed to be
 * running right now? Five independent conditions must all be true, and any one
 * of them going false stops the rAF loop inside a frame:
 *
 *   1. The page is the dashboard. Nowhere else in the panel gets a canvas.
 *   2. The governor's tier is 'high' or 'medium'. At 'low' -- or on a tier this
 *      file does not recognise, or with reduced motion -- nothing is built at
 *      all: no canvas, no listeners, no loop.
 *   3. The document is visible (`visibilitychange`).
 *   4. The canvas is intersecting the viewport (IntersectionObserver). For a
 *      fixed layer that is a *visibility* guard rather than a scroll one: it is
 *      what stops the loop if the element is ever hidden, collapsed to zero size
 *      or detached -- including by this file's own reduced-motion CSS rule.
 *   5. The page is scrolled within the first viewport. The field fades out over
 *      the first ~90% of a screen height and the loop stops dead at the bottom
 *      of that ramp. That is the scroll guard, and it is also why the field is
 *      never over the dashboard's tables: they are all below that line.
 *
 * On top of those, a `vf:motion-tier` downgrade tears the whole module down
 * permanently -- if the governor has decided the device is struggling, the
 * ambient layer is the first thing that should stop existing.
 *
 * What it costs when it IS running, worst case (tier 'high', 1920x1080 at DPR 2):
 *   - 30 frames/s, not 60 and not 120. The drift is ~10px/s; nobody can tell.
 *   - A backing store capped at 2.4 megapixels, so `clearRect` moves ~10 MB/s.
 *   - <= 40 nodes, so <= 780 pair tests per frame, most rejected on a single
 *     axis compare before any multiplication happens.
 *   - Four `stroke()` calls for every link on screen (links are bucketed by
 *     opacity so they share paths), one batched `fill()` for every node, and at
 *     most three more for packets. No per-frame allocation, no string building,
 *     no shadowBlur, no filters, no getImageData.
 *
 * Nothing in here touches page content, so rule two -- nothing may be left
 * unreadable -- holds trivially: the failure mode of every path below is a
 * missing background texture.
 */

/* Marks every node this module appends, so a run that was interrupted before
   its disposers ran still gets swept up by the next one. gsap.context() reverts
   tweens; it does not delete DOM, cancel rAF or disconnect observers. */
const OWNED = 'data-vf-ambient'
const STYLE_ID = 'vf-ambient'

/* Ceiling on the canvas backing store, in device pixels. Independent of the DPR
   cap below and the reason a 4K screen does not melt: at DPR 2 a full-screen 4K
   canvas would be 33 megapixels to clear thirty times a second. Above this the
   effective ratio drops below 1 and the layer is drawn small and upscaled --
   which for hairlines at 14% alpha is indistinguishable, and for fill rate is
   the difference between 10 MB/s and 400 MB/s. */
const MAX_BACKING_PX = 2_400_000

/* Hard cap on links drawn per frame. 40 nodes can in principle produce 780, but
   only if every node is on top of every other; this is the allocation size for
   the segment buffers, never a normal frame's load. */
const MAX_SEGMENTS = 800

/* Links are quantised into this many opacity buckets so that all the links at a
   similar distance share one path and one stroke() call. Four is enough for the
   falloff to read as continuous at these alphas. */
const BUCKETS = 4

/*
 * Per-tier budgets. There is deliberately no entry for 'low': a missing entry is
 * how this module says "do not exist", and that is also the correct response to
 * a tier string it has never heard of.
 *
 *   cap     hard ceiling on nodes, whatever the viewport size
 *   density one node per this many CSS px^2, so a small window is not crowded
 *   link    FLOOR for the connection radius in CSS px -- see fitReach()
 *   fps     frame ceiling; the loop skips frames rather than easing off
 *   ratio   DPR ceiling (the brief's cap of 2, trimmed at medium)
 *   packets concurrent travelling packets
 *   gap     seconds between packet spawns, [min, max]
 */
const TIERS = {
    high: { cap: 40, density: 46000, link: 158, fps: 30, ratio: 2, packets: 3, gap: [3.5, 7] },
    medium: { cap: 20, density: 62000, link: 138, fps: 20, ratio: 1.5, packets: 1, gap: [6, 11] },
}

/*
 * Peak alphas, before the scroll fade. The brightest link bucket is 14% of the
 * panel's primary on a gray-50 page -- below the contrast of Filament's own
 * hairline rings, which is the bar this has to stay under. Dark mode gets a
 * little more because the same alpha over gray-950 carries less.
 */
const ALPHA = {
    light: { link: 0.14, node: 0.24, packet: 0.5 },
    dark: { link: 0.18, node: 0.3, packet: 0.55 },
}

/* Connection radius as a fraction of the mean distance between nodes. Tuned so
   that on a normal laptop viewport it lands within a few pixels of the per-tier
   `link` value, and then holds that same link density at every other size. */
const LINK_SPAN = 0.75

const NODE_RADIUS = 1.4
const PACKET_RADIUS = 2
const PACKET_HALO_RADIUS = 6
const TAU = Math.PI * 2

/* Drift speed range in CSS px/s. Slow enough that a still screenshot taken a
   second apart looks identical, which is the point of ambient. */
const DRIFT_MIN = 4
const DRIFT_MAX = 12

/* Height of the fade ramp, as a fraction of the viewport. Past this the loop is
   not merely invisible, it is stopped. */
const FADE_SCREENS = 0.9

const RANK = { low: 0, medium: 1, high: 2 }

/*
 * The panel's primary, reached through a custom property rather than hard-coded,
 * so the tint follows Filament's palette and a themer can override the ambient
 * layer alone without touching this file. `color` on the canvas is inert -- it
 * draws no text -- which makes it a free carrier for the resolved value.
 *
 * The reduced-motion rule is a second lock on top of the runtime's: if this
 * layer somehow survives into a reduced-motion session, `display: none` makes
 * the IntersectionObserver report it as off-screen and the loop stops itself.
 */
const CSS = `
:root {
    --vf-ambient-tint: var(--primary-500, #f59e0b);
}

:root.dark {
    --vf-ambient-tint: var(--primary-400, #fbbf24);
}

canvas[${OWNED}] {
    position: fixed;
    inset: 0;
    z-index: -1;
    display: block;
    width: 100%;
    height: 100%;
    pointer-events: none;
    /* html.fi paints no background, so .fi-body's propagates to the viewport
       canvas and this layer sits above it but below every in-flow descendant --
       which is what keeps it behind the page's own cards and text. */
    contain: layout paint;
    color: var(--vf-ambient-tint);
}

@media (prefers-reduced-motion: reduce) {
    canvas[${OWNED}] {
        display: none;
    }
}
`

/* Survives across runs: the runtime re-invokes this function on every
   `livewire:navigated` without re-importing the module, so this is where the
   previous run's teardown waits to be executed if its context revert did not
   already reach it. */
let disposers = []

function runDisposers() {
    while (disposers.length) {
        const dispose = disposers.pop()

        try {
            dispose()
        } catch (error) {
            // A failing disposer must not strand the ones behind it -- the rAF
            // cancel and the canvas removal are in this list.
            console.warn('[vpn-forge motion] ambient cleanup failed', error)
        }
    }
}

/**
 * Trailing slashes removed, empty normalised to '/', so two spellings of the
 * same panel path compare equal.
 *
 * @param {string} path
 * @returns {string}
 */
function normalizePath(path) {
    if (!path) {
        return '/'
    }

    const trimmed = path.replace(/\/+$/, '')

    return trimmed === '' ? '/' : trimmed
}

/**
 * Every navigable destination in the sidebar lives strictly underneath the
 * current path, which is only true at the panel's root -- and the panel's root
 * is where Filament mounts the Dashboard (`Dashboard::$routePath = '/'`).
 *
 * Structural on purpose: it depends on neither a Livewire internal nor a PHP
 * class name, so it is the signal that keeps working if either changes.
 *
 * @returns {boolean}
 */
function atPanelRoot() {
    const links = document.querySelectorAll('.fi-sidebar-nav .fi-sidebar-item-btn[href]')

    // A sidebar with fewer than three destinations cannot establish a root:
    // "everything else is below me" is vacuously true with nothing else.
    if (links.length < 3) {
        return false
    }

    const here = normalizePath(window.location.pathname)
    const prefix = here === '/' ? '/' : `${here}/`
    let below = 0

    for (const link of links) {
        let path

        try {
            path = normalizePath(new URL(link.href, window.location.origin).pathname)
        } catch (error) {
            // An unparseable or cross-origin destination means this test cannot
            // conclude anything, so it concludes nothing.
            return false
        }

        if (path === here) {
            continue
        }

        if (!path.startsWith(prefix)) {
            return false
        }

        below += 1
    }

    return below >= 2
}

/**
 * @returns {boolean} whether the page currently rendered is the dashboard.
 */
function isDashboard() {
    const page = document.querySelector('.fi-main .fi-page') ?? document.querySelector('.fi-page')

    if (!page) {
        return false
    }

    // Livewire writes the component's registered name into the snapshot it
    // stamps on the component's root element, and `.fi-page` IS that root for
    // every Filament page. `Filament\Pages\Dashboard` registers as
    // `filament.pages.dashboard`; a project subclass keeps the same last
    // segment. Anchored to the end of the name so a hypothetical
    // `dashboard-settings` page does not match.
    const snapshot = page.getAttribute('wire:snapshot')

    if (snapshot && /"name"\s*:\s*"[^"]*dashboard"/i.test(snapshot)) {
        return true
    }

    return atPanelRoot()
}

/**
 * Resolve `--vf-ambient-tint` to numeric RGB.
 *
 * Filament emits its palette as `oklch()`, and `getComputedStyle` hands that
 * back in the same space rather than as an rgb() triple -- so the value is
 * painted into a one-pixel canvas and read back instead of parsed. That works
 * for any colour syntax the browser itself understands, and degrades correctly
 * for ones it does not: an assignment `<canvas>` cannot parse is ignored, which
 * leaves the fallback already sitting in `fillStyle` and paints that instead.
 *
 * Called twice in the life of a page at most -- once on build, once if the theme
 * is toggled -- so the getImageData readback costs nothing worth optimising.
 *
 * @param {HTMLElement} carrier  element whose computed `color` is the tint
 * @returns {[number, number, number]}
 */
function resolveTint(carrier) {
    const dark = document.documentElement.classList.contains('dark')
    const fallback = dark ? [251, 191, 36] : [245, 158, 11]

    let declared = ''

    try {
        declared = window.getComputedStyle(carrier).color
    } catch (error) {
        return fallback
    }

    if (!declared) {
        return fallback
    }

    try {
        const probe = document.createElement('canvas')
        probe.width = 1
        probe.height = 1

        const context = probe.getContext('2d', { willReadFrequently: true })

        if (!context) {
            return fallback
        }

        context.fillStyle = `rgb(${fallback[0]},${fallback[1]},${fallback[2]})`
        context.fillStyle = declared
        context.fillRect(0, 0, 1, 1)

        const pixel = context.getImageData(0, 0, 1, 1).data

        // A fully transparent result means the custom property resolved to
        // nothing at all, which is not a colour this layer can use.
        return pixel[3] === 0 ? fallback : [pixel[0], pixel[1], pixel[2]]
    } catch (error) {
        return fallback
    }
}

/**
 * Ambient background field.
 *
 * @param {{gsap: import('gsap').gsap, ScrollTrigger: object, MOTION: object}} api
 * @returns {null}
 */
export function ambientField({ gsap, MOTION }) {
    if (typeof document === 'undefined' || !document.body) {
        return null
    }

    // Undo the previous run before anything else, whatever state it was left
    // in: its disposers first, then a sweep by attribute for anything a run
    // that was interrupted before its disposers ran left behind.
    runDisposers()
    document.querySelectorAll(`[${OWNED}]`).forEach((node) => node.remove())

    const governor = window.__vfMotion
    const tier = governor?.tier ?? 'medium'

    if (governor?.reduced) {
        return null
    }

    // No entry means 'low', or a tier this file does not recognise. Both get
    // nothing built: no canvas, no listeners, no loop, no teardown to run.
    const cfg = TIERS[tier]

    if (!cfg) {
        return null
    }

    if (!isDashboard()) {
        return null
    }

    /* ------------------------------------------------------------------ *
     * Layer
     * ------------------------------------------------------------------ */

    const previousStyle = document.getElementById(STYLE_ID)

    if (previousStyle) {
        previousStyle.remove()
    }

    const style = document.createElement('style')
    style.id = STYLE_ID
    style.setAttribute(OWNED, 'style')
    style.textContent = CSS
    document.head.appendChild(style)

    const canvas = document.createElement('canvas')
    canvas.setAttribute(OWNED, 'canvas')
    canvas.setAttribute('aria-hidden', 'true')
    document.body.appendChild(canvas)

    const ctx = canvas.getContext('2d', { alpha: true })

    if (!ctx) {
        canvas.remove()
        style.remove()

        return null
    }

    /* ------------------------------------------------------------------ *
     * Palette
     * ------------------------------------------------------------------ */

    // Built once per theme and never per frame: every fill and stroke below
    // assigns one of these already-built strings, so a frame allocates nothing.
    let linkStyles = []
    let nodeStyle = ''
    let packetStyle = ''
    let packetHalo = ''

    function buildPalette() {
        const [r, g, b] = resolveTint(canvas)
        const peak = document.documentElement.classList.contains('dark')
            ? ALPHA.dark
            : ALPHA.light

        linkStyles = []

        for (let bucket = 0; bucket < BUCKETS; bucket += 1) {
            const strength = (bucket + 1) / BUCKETS

            linkStyles.push(`rgba(${r},${g},${b},${(peak.link * strength).toFixed(4)})`)
        }

        nodeStyle = `rgba(${r},${g},${b},${peak.node})`
        packetStyle = `rgba(${r},${g},${b},${peak.packet})`
        packetHalo = `rgba(${r},${g},${b},${(peak.packet * 0.22).toFixed(4)})`
    }

    buildPalette()

    /* ------------------------------------------------------------------ *
     * Geometry
     * ------------------------------------------------------------------ */

    let width = 0
    let height = 0
    let ratio = 1

    /**
     * Device pixel ratio, capped by the tier AND by a total backing-store area,
     * because the two constraints fail on different machines: the tier cap
     * protects a weak GPU on a normal screen, the area cap protects any machine
     * on a very large one.
     */
    function pixelRatio(cssWidth, cssHeight) {
        const wanted = Math.min(window.devicePixelRatio || 1, cfg.ratio)
        const area = cssWidth * cssHeight

        if (!area) {
            return 1
        }

        const affordable = Math.sqrt(MAX_BACKING_PX / area)

        // The area cap wins outright, including over a quality floor. A 4K
        // viewport needs ratio 0.54 to stay inside it, and drawing the layer
        // at 54% and letting the compositor scale it up costs nothing visible
        // -- these are hairlines at 14% alpha, and softer is if anything more
        // correct. The 0.25 is a guard against a degenerate ratio on an absurd
        // viewport, not a quality target; it only engages above 38 megapixels
        // of CSS area, which no display has.
        return Math.max(0.25, Math.min(wanted, affordable))
    }

    const nodes = []

    /* Connection radius in CSS px, derived rather than fixed -- see fitReach(). */
    let reach = cfg.link

    function targetCount() {
        const area = width * height

        return Math.max(8, Math.min(cfg.cap, Math.round(area / cfg.density)))
    }

    function spawnNode() {
        const angle = Math.random() * TAU
        const speed = DRIFT_MIN + Math.random() * (DRIFT_MAX - DRIFT_MIN)

        return {
            x: Math.random() * width,
            y: Math.random() * height,
            vx: Math.cos(angle) * speed,
            vy: Math.sin(angle) * speed,
            phase: Math.random() * TAU,
            // Each node breathes at its own rate, so the field never pulses in
            // unison -- which would read as a heartbeat rather than as texture.
            pulse: 0.35 + Math.random() * 0.5,
        }
    }

    function fitPopulation() {
        const target = targetCount()

        while (nodes.length > target) {
            nodes.pop()
        }

        while (nodes.length < target) {
            nodes.push(spawnNode())
        }
    }

    /**
     * The connection radius has to follow how far apart the nodes actually
     * ended up, not sit at a fixed pixel value.
     *
     * The population is capped per tier, so on a large screen those nodes are
     * spread much further apart than the tuned radius reaches -- at 'medium' on
     * a 4K viewport, twenty nodes over eight megapixels with a fixed 138px reach
     * produce exactly zero links, which is a scatter of dots rather than a
     * network. Scaling off the mean spacing keeps the number of links per node
     * roughly constant at every size, which is what the effect actually is.
     *
     * `cfg.link` stays the floor, so nothing shrinks below the tuned look on a
     * small window, and half the short viewport edge is the ceiling, so a line
     * never spans the screen.
     */
    function fitReach() {
        const spacing = Math.sqrt((width * height) / Math.max(1, nodes.length))

        reach = Math.min(
            Math.max(cfg.link, spacing * LINK_SPAN),
            Math.min(width, height) * 0.5,
        )
    }

    /**
     * One read pass, then one write pass. Resizing the backing store clears the
     * canvas, which is why nothing is drawn from here -- the next frame redraws,
     * and if the loop is stopped there is nothing that should be on screen
     * anyway.
     */
    function measure() {
        const cssWidth = window.innerWidth || document.documentElement.clientWidth || 0
        const cssHeight = window.innerHeight || document.documentElement.clientHeight || 0

        if (!cssWidth || !cssHeight) {
            return
        }

        const nextRatio = pixelRatio(cssWidth, cssHeight)
        const unchanged =
            Math.abs(cssWidth - width) < 1 &&
            Math.abs(cssHeight - height) < 1 &&
            Math.abs(nextRatio - ratio) < 0.01

        if (unchanged) {
            return
        }

        // Carry the field across the resize instead of re-scattering it: a
        // window drag would otherwise reshuffle the whole constellation on
        // every frame of the drag.
        if (width && height) {
            const scaleX = cssWidth / width
            const scaleY = cssHeight / height

            for (const node of nodes) {
                node.x *= scaleX
                node.y *= scaleY
            }
        }

        width = cssWidth
        height = cssHeight
        ratio = nextRatio

        // Floored, not rounded: rounding both axes up can push the backing
        // store back over MAX_BACKING_PX, and a cap that the code can exceed is
        // not a cap. Half a device pixel of coverage is not worth the exception.
        canvas.width = Math.max(1, Math.floor(cssWidth * ratio))
        canvas.height = Math.max(1, Math.floor(cssHeight * ratio))

        // Everything below draws in CSS pixels; the backing store resolution is
        // this one line's problem and nothing else's.
        ctx.setTransform(ratio, 0, 0, ratio, 0, 0)
        ctx.lineWidth = 1
        ctx.lineCap = 'round'

        fitPopulation()
        fitReach()
    }

    measure()

    // A viewport that measures zero is a page that is not laid out yet, and
    // there is nothing sensible to scatter nodes across. The first measure()
    // above is also what populated them.
    if (!width || !height) {
        canvas.remove()
        style.remove()

        return null
    }

    /* ------------------------------------------------------------------ *
     * Link buffers
     *
     * Preallocated and reused for the life of the layer. Typed arrays of node
     * indices rather than coordinates, so a packet can adopt a link it finds
     * here and keep following the two nodes as they drift apart.
     * ------------------------------------------------------------------ */

    const linkFrom = new Uint16Array(MAX_SEGMENTS)
    const linkTo = new Uint16Array(MAX_SEGMENTS)
    const linkBucket = new Uint8Array(MAX_SEGMENTS)
    let linkCount = 0

    /* ------------------------------------------------------------------ *
     * Packets
     * ------------------------------------------------------------------ */

    const packets = []
    // Reused every frame so drawing allocates nothing: [x, y] per live packet.
    const packetXY = new Float32Array(cfg.packets * 2)
    let spawnIn = cfg.gap[0]

    // Resolved once. A symmetric ease reads as something departing and arriving;
    // the panel's expo.out would have the packet appear already at its
    // destination, which is the opposite of what a packet is.
    const travel = gsap.parseEase('power2.inOut') || ((t) => t)
    const packetDuration = MOTION.slow * 1.3

    function spawnPacket() {
        if (!linkCount || packets.length >= cfg.packets) {
            return
        }

        const pick = (Math.random() * linkCount) | 0

        packets.push({
            from: linkFrom[pick],
            to: linkTo[pick],
            elapsed: 0,
        })
    }

    /* ------------------------------------------------------------------ *
     * Simulation and drawing
     * ------------------------------------------------------------------ */

    let clock = 0

    function step(dt) {
        clock += dt

        // A margin, so a node leaves and re-enters off-screen rather than
        // popping at the exact edge.
        const margin = 40
        const spanX = width + margin * 2
        const spanY = height + margin * 2

        for (const node of nodes) {
            node.x += node.vx * dt
            node.y += node.vy * dt

            if (node.x < -margin) node.x += spanX
            else if (node.x > width + margin) node.x -= spanX

            if (node.y < -margin) node.y += spanY
            else if (node.y > height + margin) node.y -= spanY
        }

        spawnIn -= dt

        if (spawnIn <= 0) {
            spawnPacket()
            spawnIn = cfg.gap[0] + Math.random() * (cfg.gap[1] - cfg.gap[0])
        }

        for (let i = packets.length - 1; i >= 0; i -= 1) {
            packets[i].elapsed += dt

            if (packets[i].elapsed >= packetDuration) {
                packets.splice(i, 1)
            }
        }
    }

    function findLinks() {
        const reachSq = reach * reach
        const count = nodes.length

        linkCount = 0

        for (let i = 0; i < count; i += 1) {
            const a = nodes[i]

            for (let j = i + 1; j < count; j += 1) {
                const b = nodes[j]

                // Rejected on one axis with two compares before any
                // multiplication happens, which is most pairs on most frames.
                const dx = a.x - b.x

                if (dx > reach || dx < -reach) {
                    continue
                }

                const dy = a.y - b.y

                if (dy > reach || dy < -reach) {
                    continue
                }

                const distSq = dx * dx + dy * dy

                if (distSq > reachSq) {
                    continue
                }

                // Falloff computed on the SQUARED distance, so there is no
                // square root anywhere in the frame. It is not a linear ramp --
                // a link at half the reach lands at 0.75 rather than 0.5 -- so
                // links hold their weight through the middle of the range and
                // then dissolve quickly at the edge. That is the right shape
                // here: it is a link appearing and disappearing that reads as
                // the network reconfiguring, not the fade in between.
                const strength = 1 - distSq / reachSq
                let bucket = (strength * BUCKETS) | 0

                if (bucket >= BUCKETS) {
                    bucket = BUCKETS - 1
                }

                linkFrom[linkCount] = i
                linkTo[linkCount] = j
                linkBucket[linkCount] = bucket
                linkCount += 1

                if (linkCount >= MAX_SEGMENTS) {
                    return
                }
            }
        }
    }

    function draw(fade) {
        ctx.clearRect(0, 0, width, height)
        ctx.globalAlpha = fade

        // One path and one stroke per opacity bucket, instead of one per link.
        for (let bucket = 0; bucket < BUCKETS; bucket += 1) {
            let opened = false

            for (let i = 0; i < linkCount; i += 1) {
                if (linkBucket[i] !== bucket) {
                    continue
                }

                if (!opened) {
                    ctx.beginPath()
                    opened = true
                }

                const a = nodes[linkFrom[i]]
                const b = nodes[linkTo[i]]

                ctx.moveTo(a.x, a.y)
                ctx.lineTo(b.x, b.y)
            }

            if (opened) {
                ctx.strokeStyle = linkStyles[bucket]
                ctx.stroke()
            }
        }

        // Every node in one path, one fill. The breathing is on the radius
        // rather than the alpha precisely so they can share it.
        ctx.beginPath()

        for (const node of nodes) {
            const radius = NODE_RADIUS * (0.82 + 0.18 * Math.sin(node.phase + clock * node.pulse))

            ctx.moveTo(node.x + radius, node.y)
            ctx.arc(node.x, node.y, radius, 0, TAU)
        }

        ctx.fillStyle = nodeStyle
        ctx.fill()

        if (packets.length) {
            // Positions resolved once into a buffer the layer owns, so the halo
            // pass and the core pass agree and the ease is evaluated once.
            // `nodes` can be shorter than it was when a packet was spawned -- a
            // resize trims the population -- so both ends are re-checked here.
            let live = 0

            for (const packet of packets) {
                const from = nodes[packet.from]
                const to = nodes[packet.to]

                if (!from || !to) {
                    continue
                }

                const t = travel(Math.min(1, packet.elapsed / packetDuration))

                packetXY[live * 2] = from.x + (to.x - from.x) * t
                packetXY[live * 2 + 1] = from.y + (to.y - from.y) * t
                live += 1
            }

            if (live) {
                // Two flat arcs instead of shadowBlur, which would re-rasterise
                // the whole path through a blur filter every frame.
                ctx.beginPath()

                for (let i = 0; i < live; i += 1) {
                    const x = packetXY[i * 2]
                    const y = packetXY[i * 2 + 1]

                    ctx.moveTo(x + PACKET_HALO_RADIUS, y)
                    ctx.arc(x, y, PACKET_HALO_RADIUS, 0, TAU)
                }

                ctx.fillStyle = packetHalo
                ctx.fill()

                ctx.beginPath()

                for (let i = 0; i < live; i += 1) {
                    const x = packetXY[i * 2]
                    const y = packetXY[i * 2 + 1]

                    ctx.moveTo(x + PACKET_RADIUS, y)
                    ctx.arc(x, y, PACKET_RADIUS, 0, TAU)
                }

                ctx.fillStyle = packetStyle
                ctx.fill()
            }
        }

        ctx.globalAlpha = 1
    }

    /* ------------------------------------------------------------------ *
     * The gate
     *
     * Four independent booleans, one loop. Every listener below does nothing
     * except flip one of them and call sync().
     * ------------------------------------------------------------------ */

    let disposed = false
    let onScreen = true
    let fade = 1
    let raf = 0
    let last = 0

    const interval = 1000 / cfg.fps

    function frame(now) {
        raf = requestAnimationFrame(frame)

        if (now - last < interval) {
            return
        }

        // Clamped: a tab that was throttled rather than fully hidden can hand
        // back a multi-second delta, and unclamped that teleports every node.
        const dt = Math.min((now - last) / 1000, 0.1)
        last = now

        try {
            step(dt)
            findLinks()
            draw(fade)
        } catch (error) {
            // The runtime's try/catch wraps the module's first call, not this
            // callback -- so without this a lost GPU context or any other
            // per-frame throw would keep rescheduling and log forever, which is
            // precisely the runaway this file exists to make impossible. One
            // failure ends the layer.
            console.warn('[vpn-forge motion] ambient frame failed, stopping', error)
            dispose()
        }
    }

    function shouldRun() {
        return !disposed && !document.hidden && onScreen && fade > 0.01
    }

    function sync() {
        if (shouldRun()) {
            if (!raf) {
                // Reset the clock so the first frame after a pause is a normal
                // frame rather than a jump proportional to how long we slept.
                last = performance.now() - interval
                raf = requestAnimationFrame(frame)
            }

            return
        }

        if (raf) {
            cancelAnimationFrame(raf)
            raf = 0

            // Leave nothing painted behind: a stopped loop must also be an
            // empty canvas, not a frozen last frame.
            ctx.clearRect(0, 0, width, height)
        }
    }

    /* ------------------------------------------------------------------ *
     * Teardown
     *
     * Registered before any listener is attached, so a failure while wiring up
     * still leaves something that removes the canvas.
     * ------------------------------------------------------------------ */

    const cleanup = []

    function listen(target, type, handler, options) {
        if (!target || !target.addEventListener) {
            return
        }

        target.addEventListener(type, handler, options)
        cleanup.push(() => target.removeEventListener(type, handler, options))
    }

    function dispose() {
        if (disposed) {
            return
        }

        disposed = true

        if (raf) {
            cancelAnimationFrame(raf)
            raf = 0
        }

        while (cleanup.length) {
            try {
                cleanup.pop()()
            } catch (error) {
                console.warn('[vpn-forge motion] ambient teardown failed', error)
            }
        }

        canvas.remove()
        style.remove()
    }

    disposers.push(dispose)

    // gsap.context() reverts tweens, not canvases, timers or observers. This
    // nested context exists only so the runtime's revert -- which runs before
    // every re-boot -- reaches the disposer above.
    gsap.context(() => () => {
        disposers = disposers.filter((entry) => entry !== dispose)
        dispose()
    })

    /* ------------------------------------------------------------------ *
     * Wiring
     * ------------------------------------------------------------------ */

    // 1. Tab visibility.
    listen(document, 'visibilitychange', sync)

    // 2. Element visibility. For a `position: fixed` layer this is not a scroll
    //    guard -- it is what catches the cases where the canvas is present but
    //    cannot be seen: display:none (including from this file's own
    //    reduced-motion rule), a zero-sized box, or a detached node.
    if (typeof IntersectionObserver === 'function') {
        const observer = new IntersectionObserver((entries) => {
            const latest = entries[entries.length - 1]

            if (!latest) {
                return
            }

            onScreen = latest.isIntersecting
            sync()
        })

        observer.observe(canvas)
        cleanup.push(() => observer.disconnect())
    }

    // 3. Scroll depth. The field belongs to the top of the dashboard: it fades
    //    across the first screen height and the loop stops at the bottom of that
    //    ramp, which is also why it is never behind the dashboard's tables.
    const onScroll = () => {
        const screen = window.innerHeight || 1
        const offset = window.scrollY || window.pageYOffset || 0
        const next = 1 - Math.min(1, offset / (screen * FADE_SCREENS))

        if (Math.abs(next - fade) < 0.004) {
            return
        }

        fade = next
        sync()
    }

    listen(window, 'scroll', onScroll, { passive: true })

    // 4. Resize. Coalesced to one frame: a window drag fires this continuously
    //    and each one reallocates a backing store.
    let resizeFrame = 0

    const onResize = () => {
        if (resizeFrame) {
            return
        }

        resizeFrame = requestAnimationFrame(() => {
            resizeFrame = 0

            if (disposed) {
                return
            }

            measure()
            onScroll()
            sync()
        })
    }

    listen(window, 'resize', onResize, { passive: true })
    cleanup.push(() => {
        if (resizeFrame) {
            cancelAnimationFrame(resizeFrame)
            resizeFrame = 0
        }
    })

    // 5. Theme. The runtime re-runs on navigation but not on a theme toggle, and
    //    the toggle only swaps `.dark` on <html> -- so the tint has to be
    //    re-resolved here or the field keeps the old theme's alphas.
    if (typeof MutationObserver === 'function') {
        const themeObserver = new MutationObserver(() => buildPalette())

        themeObserver.observe(document.documentElement, {
            attributes: true,
            attributeFilter: ['class'],
        })

        cleanup.push(() => themeObserver.disconnect())
    }

    // 6. Governor downgrade. The ambient layer is the most expensive continuous
    //    thing in the motion system, so a downgrade does not trim it -- it ends
    //    it. Both targets are listened on because the governor owns which one it
    //    dispatches from, and guessing wrong here would leave a loop running on
    //    a device that just said it is struggling.
    const onTierChange = (event) => {
        const next = event?.detail?.tier ?? window.__vfMotion?.tier

        if (typeof next !== 'string') {
            return
        }

        if (!(next in RANK) || RANK[next] < RANK[tier]) {
            dispose()
        }
    }

    listen(window, 'vf:motion-tier', onTierChange)
    listen(document, 'vf:motion-tier', onTierChange)

    // 7. SPA navigation. The runtime rebuilds everything on `livewire:navigated`,
    //    but the swap itself happens before that -- stopping here keeps the loop
    //    from drawing over a page that is being replaced.
    listen(document, 'livewire:navigating', dispose)

    onScroll()
    sync()

    return null
}
