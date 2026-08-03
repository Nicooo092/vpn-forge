/*
 * Config QR code assembly
 * -----------------------
 * When an operator opens the QR modal on a WireGuard user, the code assembles
 * instead of appearing: the three finder patterns open from their centres, then
 * a square wave blooms out of the middle of the code and fills the data modules
 * in, ring by ring, snapped to the module grid.
 *
 * THE CONSTRAINT THAT SHAPES EVERYTHING HERE: a QR code an operator cannot scan
 * is a broken feature. Nothing in this file is allowed to leave the real code
 * hidden, clipped or half-drawn, under any failure mode -- not a parse error,
 * not a thrown exception, not a backgrounded tab, not a Livewire morph landing
 * mid-animation, not a navigation. Every branch below either finishes cleanly or
 * bails to "the code is exactly as the server rendered it".
 *
 * WHAT IS ACTUALLY ON THE PAGE. The code is an <img> whose src is a base64
 * data URI of a BaconQrCode SVG (see ServiceUsersTable::qrDataUri). An <img>
 * renders its SVG in an isolated document, so the modules are not reachable
 * from the DOM -- there is nothing to select and nothing to stagger.
 *
 * So this module does not touch the <img> at all beyond hiding it. It decodes
 * the same data URI itself, and if -- and only if -- the result parses as the
 * exact shape BaconQrCode emits:
 *
 *     <svg viewBox="0 0 S S">
 *       <rect .../>                          background plate
 *       <g transform="scale(m)">             m = module size in user units
 *         <g transform="translate(4,4)">     the quiet-zone margin
 *           <path fill-rule="evenodd" d="..."/>   every dark module, one path
 *
 * ...it clones that SVG inline next to the <img>, wraps the module path in an
 * SVG <mask>, and animates the *mask* rather than the code. The path is never
 * split (its holes are separate evenodd contours -- separating them would fill
 * them in), never re-drawn and never approximated: the geometry an operator
 * ends up scanning is byte-for-byte the geometry the server rendered. The mask
 * only decides how much of it is on screen yet, and the mask shapes are laid
 * out on module boundaries, so the reveal edge always falls between modules and
 * never through one.
 *
 * When the last ring lands, the clone is removed and the real <img> is shown in
 * the same frame. The final DOM is what the server sent, with no residue.
 *
 * FOUR LAYERS OF FAILSAFE, in the order they fire:
 *
 *   1. Structural. If the data URI does not decode, does not parse, or is not
 *      recognisably a BaconQrCode matrix (module count outside 21..177, or not
 *      a legal QR version), no overlay is built and the <img> is never hidden.
 *      An unrecognised image is left completely alone.
 *   2. Timed. A native setTimeout -- not gsap.delayedCall -- forces the finished
 *      state 1000ms after the code is spotted, whatever else is happening. It is
 *      armed *before* anything is hidden. setTimeout is used deliberately: GSAP's
 *      ticker is driven by requestAnimationFrame, which stops in a background
 *      tab, so a delayedCall could sit unfired for as long as the operator is
 *      looking somewhere else. The whole sequence budgets 820ms worst case
 *      (200ms waiting for the image to decode + 620ms of animation), so this
 *      never fires in normal operation.
 *   3. Event. visibilitychange snaps every in-flight reveal to its finished
 *      state, so a tab switch cannot freeze a half-drawn code.
 *   4. Lifecycle. The finisher is registered on the gsap context, so the
 *      runtime's teardown on `livewire:navigated` restores the code too.
 *
 * All four run the same idempotent finish(), and finish() ends with the <img>
 * visible. There is no path through this file that ends any other way.
 *
 * CONTAINING BLOCKS (panel rule): the overlay is absolutely positioned, which
 * needs a positioned ancestor. The element that gets `position: relative` is the
 * <img>'s own parent -- the white plate, which holds the image and nothing else.
 * No control, and no modal, is ever inside it. The property is written directly
 * rather than through GSAP and removed by finish(), so its lifetime is exactly
 * the lifetime of the overlay.
 *
 * SELECTORS: this module matches no Filament class name at all. It finds its
 * target by src (`img[src^="data:image/svg+xml"]`) and then proves the target is
 * a QR code by parsing it, which is stronger than any class name could be and
 * cannot rot when Filament renames something.
 */

const SVG_NS = 'http://www.w3.org/2000/svg'

/** Any inline-SVG image. Narrowed to an actual QR code by parsing it, below. */
const QR_IMG = 'img[src^="data:image/svg+xml"]'

/** Marks the clone, so strays from a previous run are removed at the start of
 *  this one -- gsap.context() reverts tweens, not appended DOM. */
const OVERLAY_MARK = 'data-vf-qr'

/** Marks an <img> this module has hidden, so a stray can be un-hidden even if
 *  the run that hid it is long gone. */
const HIDDEN_MARK = 'data-vf-qr-hidden'

/** Hard deadline from the moment a code is spotted to the moment it is
 *  guaranteed fully visible, whatever happened in between. */
const FAILSAFE_MS = 1000

/** How long to wait for the image to decode and take up a box before giving up
 *  on it. Bounded so the failsafe budget above still holds. */
const SETTLE_MS = 200

/**
 * Per-tier budgets. `maxRings` caps how many mask shapes the wave is made of:
 * an SVG mask is re-rasterised on every change, so ring count is the single
 * number that decides what this costs per frame. Everything else is timing.
 *
 * The `low` tier is absent on purpose -- see the early return in qrReveal.
 */
const TIERS = {
    high: {
        maxRings: 22,
        eye: 0.3,
        eyeStagger: 0.055,
        waveAt: 0.14,
        ring: 0.2,
        spread: 0.28,
        pulse: true,
    },
    medium: {
        maxRings: 9,
        eye: 0.22,
        eyeStagger: 0.04,
        waveAt: 0.1,
        ring: 0.16,
        spread: 0.2,
        pulse: false,
    },
}

/** Mask ids are document-global, and two QR modals can briefly coexist while one
 *  is closing. A counter is the cheapest guarantee they never collide. */
let uid = 0

/**
 * Config QR code assembly.
 *
 * @param {{gsap: import('gsap').gsap, MOTION: object}} api
 */
export function qrReveal({ gsap, MOTION }) {
    if (typeof document === 'undefined' || !document.body) {
        return null
    }

    const motion = window.__vfMotion
    const tier = (motion && motion.tier) || 'medium'

    // Clean up before deciding whether to run: a previous run may have left a
    // clone behind (a navigation while a modal was open), and that clone has to
    // go even on a device that will not get an animation this time.
    sweepStrays()

    if (motion && motion.reduced) {
        return null
    }

    // A masked SVG re-rasterises the whole code every frame it changes. On a low
    // tier that is exactly the work not worth doing, and the alternative -- the
    // code simply being there -- is not a degraded experience for someone who
    // opened the modal to point a phone at it.
    const budget = TIERS[tier]

    if (!budget) {
        return null
    }

    const scope = createScope()

    // gsap.context() reverts tweens, not listeners, observers, timers or
    // appended nodes. This nested context exists only so the runtime's revert()
    // reaches the disposer below.
    gsap.context(() => () => scope.dispose())

    /*
     * gsap.context() with no argument hands back the context we are running
     * inside -- the runtime's. Tweens created later, from a MutationObserver
     * callback, would otherwise belong to no context at all: nothing would
     * revert them, and the inline styles they left behind would survive the next
     * navigation frozen mid-animation.
     *
     * The result is fished out through a closure rather than returned, because
     * context.add() treats a returned *function* as a cleanup callback.
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

    /** Every reveal currently in flight, keyed by its idempotent finisher. */
    const pending = new Set()

    const finishAll = () => {
        // Copied first: each finisher removes itself from the set as it runs.
        Array.from(pending).forEach((finish) => finish())
    }

    scope.add(finishAll)

    // A backgrounded tab stops requestAnimationFrame, which stops GSAP. Rather
    // than let a code sit half-drawn until the operator comes back, snap it.
    scope.on(document, 'visibilitychange', () => {
        if (document.hidden) {
            finishAll()
        }
    })

    // One <img> is only ever revealed once, even if a morph re-runs the observer
    // over the same node.
    const claimed = new WeakSet()

    const consider = (img) => {
        if (!img || claimed.has(img) || img.hasAttribute(HIDDEN_MARK)) {
            return
        }

        claimed.add(img)
        reveal({ gsap, MOTION, budget, img, scope, owned, pending })
    }

    // The modal that carries the code is rendered by a Livewire round-trip long
    // after this module runs, so the code has to be waited for rather than
    // looked for. The initial sweep covers the case where one is already open --
    // an SPA navigation with the modal on screen, say.
    document.querySelectorAll(QR_IMG).forEach(consider)

    if (typeof MutationObserver !== 'function') {
        return null
    }

    const observer = new MutationObserver((records) => {
        for (const record of records) {
            for (const node of record.addedNodes) {
                if (node.nodeType !== 1) {
                    continue
                }

                if (node.matches && node.matches(QR_IMG)) {
                    consider(node)
                    continue
                }

                if (node.querySelectorAll) {
                    node.querySelectorAll(QR_IMG).forEach(consider)
                }
            }
        }
    })

    observer.observe(document.body, { childList: true, subtree: true })
    scope.add(() => observer.disconnect())

    return null
}

/* ------------------------------------------------------------------------- */
/* Lifecycle                                                                  */
/* ------------------------------------------------------------------------- */

/**
 * A bag of teardown callbacks. Every listener, observer, timer and inserted node
 * in this file goes through one of these, so nothing outlives a context revert.
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
                    // A failing disposer must not strand the ones behind it --
                    // one of them is what puts a QR code back on screen.
                    console.warn('[vpn-forge motion] qr cleanup failed', error)
                }
            }
        },
    }
}

/**
 * Undo anything a previous run left behind. Runs before this run decides whether
 * to animate at all, so a device that drops to a low tier mid-session (or a
 * reduced-motion preference switched on while a modal is open) still gets its
 * code back rather than inheriting a hidden one.
 */
function sweepStrays() {
    document.querySelectorAll(`[${OVERLAY_MARK}]`).forEach((node) => node.remove())

    document.querySelectorAll(`[${HIDDEN_MARK}]`).forEach((img) => {
        img.removeAttribute(HIDDEN_MARK)
        img.style.removeProperty('opacity')
    })
}

/* ------------------------------------------------------------------------- */
/* Decoding                                                                   */
/* ------------------------------------------------------------------------- */

/**
 * The SVG text behind a data URI, or null. Both the base64 form Bacon's writer
 * is wrapped in here and a plain percent-encoded one are accepted; anything
 * else, including anything implausibly large for a QR code, is refused.
 */
function decodeSvg(src) {
    if (typeof src !== 'string') {
        return null
    }

    const comma = src.indexOf(',')

    if (comma < 0 || src.length > 262144) {
        return null
    }

    const meta = src.slice(0, comma)
    const body = src.slice(comma + 1)

    try {
        return /;base64/i.test(meta) ? window.atob(body) : decodeURIComponent(body)
    } catch (error) {
        return null
    }
}

/**
 * Recognise the document as a BaconQrCode render and recover the numbers the
 * mask needs from it. Every check is a reason to give up: this function's whole
 * job is to be certain about what it is looking at before anything gets hidden.
 *
 * @returns {{root: SVGSVGElement, size: number, margin: number, matrix: number}|null}
 */
function parseQr(text) {
    if (!text || typeof DOMParser !== 'function') {
        return null
    }

    let doc

    try {
        doc = new DOMParser().parseFromString(text, 'image/svg+xml')
    } catch (error) {
        return null
    }

    const root = doc.documentElement

    if (!root || root.namespaceURI !== SVG_NS || root.localName !== 'svg') {
        return null
    }

    // A malformed document still yields a documentElement in some engines; the
    // error element is the reliable tell.
    if (doc.getElementsByTagName('parsererror').length) {
        return null
    }

    const box = (root.getAttribute('viewBox') || '').trim().split(/[\s,]+/).map(Number)

    if (box.length !== 4 || !box.every(Number.isFinite)) {
        return null
    }

    const [minX, minY, width, height] = box

    // The renderer always emits a square viewBox at the origin. Anything else is
    // not the image this module knows how to take apart.
    if (minX !== 0 || minY !== 0 || width <= 0 || width !== height) {
        return null
    }

    const scaleGroup = root.querySelector(':scope > g[transform^="scale("]')

    if (!scaleGroup) {
        return null
    }

    const marginGroup = scaleGroup.querySelector(':scope > g[transform^="translate("]')

    if (!marginGroup || !marginGroup.querySelector('path[d]')) {
        return null
    }

    const scaleMatch = /^scale\(\s*([\d.eE+-]+)\s*\)$/.exec(
        (scaleGroup.getAttribute('transform') || '').trim(),
    )
    const marginMatch = /^translate\(\s*([\d.eE+-]+)\s*[,\s]\s*([\d.eE+-]+)\s*\)$/.exec(
        (marginGroup.getAttribute('transform') || '').trim(),
    )

    if (!scaleMatch || !marginMatch) {
        return null
    }

    const scale = Number(scaleMatch[1])
    const margin = Number(marginMatch[1])

    if (!Number.isFinite(scale) || scale <= 0) {
        return null
    }

    // The quiet zone is square, and four modules by default. A wildly different
    // value means the render is not what this module modelled.
    if (!Number.isFinite(margin) || margin !== Number(marginMatch[2]) || margin < 0 || margin > 16) {
        return null
    }

    /*
     * `scale` is the module size in user units, written to three decimals, so
     * the module count comes back off it exactly once it is rounded: a 65-module
     * code at size 320 stores scale=4.384, and 320/4.384 = 72.99 -> 73 modules
     * across including both margins.
     *
     * The version check is the real guard. QR symbols run from 21 modules
     * (version 1) to 177 (version 40) in steps of four, so anything that fails
     * it is not a QR code and this module has misread something.
     */
    const across = Math.round(width / scale)
    const matrix = across - margin * 2

    if (matrix < 21 || matrix > 177 || (matrix - 21) % 4 !== 0) {
        return null
    }

    return { root, size: width, margin, matrix }
}

/* ------------------------------------------------------------------------- */
/* Overlay construction                                                       */
/* ------------------------------------------------------------------------- */

function svgEl(name, attrs) {
    const node = document.createElementNS(SVG_NS, name)

    Object.keys(attrs).forEach((key) => node.setAttribute(key, String(attrs[key])))

    return node
}

/**
 * Clone the decoded code, put its module path behind a mask, and fill the mask
 * with the shapes the timeline will bring in.
 *
 * The mask lives in the outer user space (0..size), not inside the renderer's
 * scale/translate pair, so every shape below converts module indices to user
 * units itself: module n starts at (n + margin) * pitch.
 *
 * @returns {{
 *   node: SVGSVGElement,
 *   eyes: {node: SVGRectElement, x: number, y: number, span: number}[],
 *   rings: SVGRectElement[],
 *   pulse: SVGRectElement|null,
 *   centre: number,
 *   reach: number,
 * }|null}
 */
function buildOverlay(qr, box, budget) {
    const node = document.importNode(qr.root, true)
    const scaleGroup = node.querySelector('g[transform^="scale("]')

    if (!scaleGroup || !scaleGroup.parentNode) {
        return null
    }

    const size = qr.size
    const pitch = size / (qr.matrix + qr.margin * 2)
    const centre = size / 2
    const at = (module) => (module + qr.margin) * pitch

    const maskId = `vf-qr-mask-${++uid}`
    const mask = svgEl('mask', {
        id: maskId,
        maskUnits: 'userSpaceOnUse',
        x: 0,
        y: 0,
        width: size,
        height: size,
        // Stated rather than left to the default, so no stylesheet that happens
        // to touch mask-type can silently invert what is visible.
        style: 'mask-type:luminance',
    })

    const defs = svgEl('defs', {})
    defs.appendChild(mask)

    const masked = svgEl('g', { mask: `url(#${maskId})` })
    scaleGroup.parentNode.insertBefore(masked, scaleGroup)
    masked.appendChild(scaleGroup)
    node.insertBefore(defs, node.firstChild)

    /* --- finder patterns -------------------------------------------------
       The three 7x7 eyes, at the corners of the matrix. Each mask rect carries
       one module of bleed on every side, which is free: a QR code always has a
       one-module light separator on the inner sides of a finder and the quiet
       zone on the outer ones, so the bleed can only ever reveal white. Without
       it the mask edge would land exactly on the outermost dark modules and
       hairline them while the eye is still growing. The bleed stops one module
       short of the format information, which stays with the wave. */
    const eyeSpan = 9 * pitch
    const eyes = [
        [-1, -1],
        [qr.matrix - 8, -1],
        [-1, qr.matrix - 8],
    ].map(([mx, my]) => {
        const x = at(mx)
        const y = at(my)

        return {
            node: svgEl('rect', {
                x,
                y,
                width: eyeSpan,
                height: eyeSpan,
                fill: '#fff',
                // Hidden as an attribute, not by a tween, so there is no frame
                // in which a fully revealed code could be painted before GSAP's
                // first tick lands.
                opacity: 0,
            }),
            x,
            y,
            span: eyeSpan,
        }
    })

    eyes.forEach((eye) => mask.appendChild(eye.node))

    /* --- the wave --------------------------------------------------------
       Concentric square rings expanding from the centre module. Each ring is a
       stroked rect: a stroke is centred on its path, so a rect whose half-side
       is s with stroke-width w covers the band [s - w/2, s + w/2] and
       consecutive rings tile without a seam.

       Ring thickness is a whole number of modules, so every boundary the wave
       crosses is a boundary between modules -- the code fills in module by
       module rather than being wiped through. `maxRings` then caps how many
       shapes that costs, because the mask is re-rasterised on every frame one
       of them changes. */
    const ringModules = Math.max(1, Math.ceil(qr.matrix / 2 / budget.maxRings))
    const thickness = ringModules * pitch
    const inner = pitch / 2
    const ringCount = Math.max(1, Math.ceil((centre - inner) / thickness))

    const rings = [
        // The centre module itself, filled: ring 0 has no radius to stroke.
        svgEl('rect', {
            x: centre - inner,
            y: centre - inner,
            width: pitch,
            height: pitch,
            fill: '#fff',
            opacity: 0,
        }),
    ]

    for (let index = 1; index <= ringCount; index += 1) {
        const half = inner + (index - 0.5) * thickness

        rings.push(
            svgEl('rect', {
                x: centre - half,
                y: centre - half,
                width: half * 2,
                height: half * 2,
                fill: 'none',
                stroke: '#fff',
                // A hair over the nominal thickness: neighbouring bands then
                // overlap rather than risking a sub-pixel seam of unrevealed
                // modules between them.
                'stroke-width': thickness * 1.04,
                opacity: 0,
            }),
        )
    }

    rings.forEach((ring) => mask.appendChild(ring))

    /* --- wavefront accent ------------------------------------------------
       Drawn in the visible layer, not the mask: a single ring travelling just
       ahead of the reveal, in the panel's own primary colour. It is gone before
       the code is handed over, so nothing coloured is ever on top of a code
       anyone could point a camera at. */
    let pulse = null

    if (budget.pulse) {
        pulse = svgEl('rect', {
            x: centre,
            y: centre,
            width: 0,
            height: 0,
            fill: 'none',
            'stroke-width': Math.max(1, pitch * 0.35),
            opacity: 0,
            // The colour goes in an inline style, not a `stroke` presentation
            // attribute: var() substitution into presentation attributes is not
            // something every engine gets right, and an unresolved stroke
            // computes to `none` rather than falling back to anything.
            style: 'stroke: var(--primary-500, #6366f1)',
        })

        node.appendChild(pulse)
    }

    node.setAttribute(OVERLAY_MARK, '')
    node.setAttribute('aria-hidden', 'true')
    node.setAttribute('focusable', 'false')
    node.setAttribute('width', String(box.width))
    node.setAttribute('height', String(box.height))
    node.setAttribute('preserveAspectRatio', 'xMidYMid meet')
    node.style.cssText =
        'position:absolute;display:block;pointer-events:none;' +
        `left:${box.left}px;top:${box.top}px;width:${box.width}px;height:${box.height}px;`

    // `reach` carries the accent ring out to roughly the edge of the matrix,
    // stopping inside the viewport so it fades rather than being clipped.
    return { node, eyes, rings, pulse, centre, reach: size * 0.95 }
}

/* ------------------------------------------------------------------------- */
/* Reveal                                                                     */
/* ------------------------------------------------------------------------- */

/**
 * Take one <img> from "just appeared" to "assembled and scannable", or to
 * "untouched" if anything at all is not as expected.
 */
function reveal({ gsap, MOTION, budget, img, scope, owned, pending }) {
    let settled = false
    let timeline = null
    let overlay = null
    let timer = 0
    let frame = 0
    let restoreParent = null

    /**
     * The one exit. Idempotent, synchronous, and it ends with the code visible.
     * Everything in this file that can go wrong routes through here.
     */
    const finish = () => {
        if (settled) {
            return
        }

        settled = true
        pending.delete(finish)

        /*
         * The code goes back first, before the timeline is killed and before the
         * overlay is unpicked. Those are tidying; this is the feature. If
         * anything below this point were to throw, the operator would still have
         * a scannable code in front of them.
         *
         * Written and removed directly rather than through GSAP, for the same
         * reason: it must not depend on a context, a ticker or a tween still
         * being alive. Nothing is painted between here and the end of this
         * function, so the overlay coming off a few lines later is not a flash --
         * it is the same frame.
         */
        img.style.removeProperty('opacity')
        img.removeAttribute(HIDDEN_MARK)

        if (timer) {
            clearTimeout(timer)
            timer = 0
        }

        if (frame) {
            cancelAnimationFrame(frame)
            frame = 0
        }

        if (timeline) {
            const running = timeline
            timeline = null
            running.kill()
        }

        if (overlay) {
            overlay.remove()
            overlay = null
        }

        if (restoreParent) {
            const restore = restoreParent
            restoreParent = null
            restore()
        }
    }

    pending.add(finish)

    // Armed first, before a single thing is measured, decoded or hidden. From
    // here on the code is guaranteed to be back within a second no matter what
    // the rest of this function does -- including throwing.
    timer = setTimeout(finish, FAILSAFE_MS)

    /*
     * The only thing worth waiting for is the image having a box. A data URI
     * decodes within a frame or two, but the height comes from the decoded
     * intrinsic ratio (the markup sets `h-auto`), so until it lands there is
     * nothing to measure. If it never lands -- a container that is display:none,
     * an image that failed to decode -- the code is left exactly as it is.
     *
     * Note what is deliberately NOT waited for: Filament opens its modals with a
     * `scale-95` -> `scale-100` transition, so for the first ~300ms the code
     * sits under a live ancestor transform. Everything below measures with
     * offsetLeft/offsetTop/offsetWidth/offsetHeight, which are layout values and
     * ignore ancestor transforms entirely, so the overlay can be placed
     * correctly while the modal is still on its way in. It then rides the same
     * transition as the image it stands in for, because it is inside it.
     */
    const startedAt = Date.now()

    const attempt = () => {
        frame = 0

        if (settled) {
            return
        }

        if (!img.isConnected) {
            finish()
            return
        }

        const laidOut =
            img.complete && img.naturalWidth > 0 && img.offsetWidth > 8 && img.offsetHeight > 8

        if (!laidOut) {
            if (Date.now() - startedAt < SETTLE_MS) {
                frame = requestAnimationFrame(attempt)
            } else {
                finish()
            }

            return
        }

        try {
            play()
        } catch (error) {
            console.warn('[vpn-forge motion] qr reveal failed', error)
            finish()
        }
    }

    const play = () => {
        const parent = img.parentElement

        if (!parent) {
            finish()
            return
        }

        const qr = parseQr(decodeSvg(img.getAttribute('src')))

        // Not a code this module can prove it understands. Nothing has been
        // hidden at this point, so bailing costs the operator nothing.
        if (!qr) {
            finish()
            return
        }

        if (getComputedStyle(parent).position === 'static') {
            // The plate holds the image and nothing else, so making it the
            // containing block cannot capture a control or a modal. Removed
            // again by finish(), so it lives exactly as long as the overlay.
            //
            // This is also the one write that has to happen before a read:
            // offsetLeft/offsetTop are measured from the nearest positioned
            // ancestor, so the plate has to become that ancestor first. Going
            // static -> relative with no offsets does not move anything, so the
            // reflow it costs changes nothing on screen.
            parent.style.position = 'relative'
            restoreParent = () => parent.style.removeProperty('position')
        }

        const box = {
            left: img.offsetLeft,
            top: img.offsetTop,
            width: img.offsetWidth,
            height: img.offsetHeight,
        }

        const built = buildOverlay(qr, box, budget)

        if (!built) {
            finish()
            return
        }

        overlay = built.node
        parent.insertBefore(overlay, img.nextSibling)

        // Hidden only once the replacement is already in the document, so there
        // is no frame in which neither is on screen.
        img.style.opacity = '0'
        img.setAttribute(HIDDEN_MARK, '')

        timeline = owned(() =>
            gsap.timeline({
                // The handover: the overlay is removed and the real code shown
                // in the same tick the last ring finished, so there is no
                // crossfade to sit through and nothing to scan through.
                onComplete: finish,
            }),
        )

        if (!timeline) {
            finish()
            return
        }

        /*
         * The eyes open from their own centres -- the inner 3x3 first, then the
         * ring around it, because that is the order a square growing out of the
         * middle of a finder pattern uncovers it.
         *
         * Geometry, not `scale`. A transform would send GSAP looking for the
         * element's bounding box to resolve `transformOrigin`, and these rects
         * live inside a <mask> inside <defs> where getBBox() is unreliable
         * enough that GSAP carries a documented workaround for it. Tweening the
         * four attributes sidesteps the question entirely, and one tween per eye
         * keeps each one's target geometry explicit.
         */
        built.eyes.forEach((eye, index) => {
            const midX = eye.x + eye.span / 2
            const midY = eye.y + eye.span / 2

            timeline.fromTo(
                eye.node,
                { opacity: 0, attr: { x: midX, y: midY, width: 0, height: 0 } },
                {
                    opacity: 1,
                    attr: { x: eye.x, y: eye.y, width: eye.span, height: eye.span },
                    duration: budget.eye,
                    ease: MOTION.ease,
                },
                index * budget.eyeStagger,
            )
        })

        // Then the data modules, filling outward from the centre. The stagger
        // eases rather than the individual rings: the wavefront is what should
        // accelerate, not the brightness of any one band.
        timeline.fromTo(
            built.rings,
            { opacity: 0 },
            {
                opacity: 1,
                duration: budget.ring,
                ease: 'none',
                stagger: { amount: budget.spread, from: 'start', ease: 'power1.in' },
            },
            budget.waveAt,
        )

        if (built.pulse) {
            const half = built.reach / 2

            timeline.fromTo(
                built.pulse,
                {
                    opacity: 0.5,
                    attr: { x: built.centre, y: built.centre, width: 0, height: 0 },
                },
                {
                    opacity: 0,
                    attr: {
                        x: built.centre - half,
                        y: built.centre - half,
                        width: built.reach,
                        height: built.reach,
                    },
                    duration: budget.spread + budget.ring,
                    ease: 'power2.out',
                },
                budget.waveAt,
            )
        }
    }

    // The scope's finishAll() covers a teardown that lands before the first
    // frame ever runs.
    frame = requestAnimationFrame(attempt)
}
