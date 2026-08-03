/**
 * Typographic reveals.
 * --------------------
 * One effect, done properly: a heading's words rise out of their own line, each
 * inside a mask that hides everything below it. It is the single most expensive-
 * looking thing you can do to text, and it costs one composited transform per
 * word.
 *
 * SplitText is a Club plugin and is not available here, so the splitter is
 * written out below. It is deliberately small and deliberately suspicious: it
 * refuses anything it cannot put back byte for byte, and what it produces exists
 * only for the second the reveal takes -- the moment the last word lands, the
 * original text node goes back in and every generated span is gone. Nothing this
 * module creates survives its own animation.
 *
 * That "restore immediately" rule is not tidiness, it settles four problems at
 * once:
 *
 *   - Screen readers. A split heading is a pile of one-word spans. They are
 *     marked `aria-hidden` and the container carries the real text as an
 *     `aria-label` for the duration, and then the actual text comes back so the
 *     accessibility tree is only lying for as long as the animation runs.
 *   - Livewire. Its morph diffs against server HTML that has no spans in it, so
 *     anything we leave behind is something for it to fight over later.
 *   - Rule 4 (transforms and fixed descendants). The spans hold transforms, so
 *     they are removed rather than left holding one forever. Headings contain no
 *     controls and therefore no modals, but the discipline costs nothing.
 *   - Readability. If the reveal never finishes -- interrupted, starved, a
 *     trigger that never fired -- a wall-clock timer puts the text back anyway.
 *     Worst case an operator waits ~1.3s to read a heading; there is no path
 *     where a heading stays hidden.
 *
 * Scope is headings only: the page `<h1>`, section headings, and an opt-in class
 * for the panel's own Blade. Nothing that carries a measurement is ever split --
 * a value taken apart into per-character spans mid-poll is a reading an operator
 * cannot trust.
 */

import { CustomEase } from 'gsap/CustomEase'

/* Marks a container whose text is currently replaced by generated spans. Also
   how a LATER boot finds work an earlier one left unfinished: the closure that
   knew about those nodes is gone by then, the attribute is not. */
const OWNED = 'data-vf-text'

/* The exact original text, whitespace included, kept in the DOM for the same
   reason. */
const ORIGINAL = 'data-vf-text-from'

/* Only present when the element already had an `aria-label` of its own, so
   restoring can tell "had none" from "had an empty one". */
const LABEL = 'data-vf-text-label'

/* Long headings are prose, and prose split into fourteen masked words reads as
   an effect rather than as a page arriving. Past these the element is left
   exactly as the server rendered it. */
const MAX_CHARS = 60
const MAX_WORDS = 14

/* Per-character is reserved for short, large titles -- at section-heading size
   it is invisible, and on a long heading it is a wall of layers. */
const CHAR_MAX_CHARS = 22
const CHAR_MAX_WORDS = 4

/* A page with more sections than this is a list, and the reveal stops being a
   composition and starts being a tic. */
const MAX_SECTION_HEADINGS = 12

/* Per-character costs one composited layer per glyph, and this panel runs on a
   2-core box. Small screens get the word version. */
const DESKTOP = '(min-width: 64rem)'

/*
 * The mask. `overflow: hidden` on an inline-block is what clips the word, but it
 * also moves the box's baseline to its bottom margin edge -- so alignment has to
 * be taken back by hand or every word sits a descender too high.
 *
 * `vertical-align: bottom` aligns the bottom margin edge with the line box
 * instead, and the negative margins cancel the padding exactly, so the margin
 * box ends up the same size as the text it wraps and the line box does not grow.
 * The padding survives as clipping room: without it the mask cuts the tails off
 * g, j, p, y and the accents off É and À, which in a French panel is not an edge
 * case.
 */
const MASK_CSS =
    'display:inline-block;overflow:hidden;vertical-align:bottom;' +
    'padding:0.08em 0 0.22em;margin:-0.08em 0 -0.22em;'

const LEAF_CSS = 'display:inline-block;will-change:transform;'

/* Percentage of its own height a word starts below the mask. Over 100 because
   the mask is taller than the text by the clipping room above, and a word whose
   accents peek over the edge before it moves is worse than no mask at all. */
const RISE = 120

/* Milliseconds after a reveal is due to have finished before the text is forced
   back regardless of what the tween did. */
const FAILSAFE = 400

/* Survives the runtime's teardown/boot cycle: the ease is a global registration,
   so it is created once and reused rather than re-parsed on every navigation. */
let riseEase = null

/* Wall-clock failsafes owned by the current run. */
const timers = []

/**
 * A curve for text leaving a mask, which the stock set does not have.
 *
 * The two candidates both miss, in opposite directions. `expo.out` is three
 * quarters done a fifth of the way in: the word is out of the mask before the
 * eye has registered there was one, so the whole effect reads as a fade.
 * `power3.out` is under half done at the same point, which on a heading you are
 * waiting to read is sluggish.
 *
 * This sits between them -- around 60% at a fifth, 90% at the halfway mark, and
 * the last tenth spread across the remaining half. The word clears its mask
 * promptly and then takes its time settling, which is the part that reads as
 * weight.
 */
function ensureEase(gsap, MOTION) {
    if (riseEase) {
        return riseEase
    }

    try {
        gsap.registerPlugin(CustomEase)
        CustomEase.create('vfTextRise', 'M0,0 C0.16,0.5 0.2,1 1,1')
        riseEase = 'vfTextRise'
    } catch (error) {
        // A plugin that fails to load must not cost the panel its animation.
        riseEase = MOTION.ease
    }

    return riseEase
}

/**
 * Put a split element back exactly as it was. Idempotent, safe on a detached
 * node, and the only way out of a split -- every path (complete, interrupt,
 * failsafe, teardown, next boot) ends here.
 *
 * @param {Element} element
 */
function restore(element) {
    if (!element || !element.hasAttribute || !element.hasAttribute(OWNED)) {
        return
    }

    const original = element.getAttribute(ORIGINAL)

    if (original !== null) {
        // textContent, not innerHTML: the split only ever runs on an element
        // whose content was a single text node, so this reproduces the server's
        // markup character for character and re-escapes anything that needs it.
        element.textContent = original
    }

    const prior = element.getAttribute(LABEL)

    if (prior === null) {
        element.removeAttribute('aria-label')
    } else {
        element.setAttribute('aria-label', prior)
    }

    element.removeAttribute(OWNED)
    element.removeAttribute(ORIGINAL)
    element.removeAttribute(LABEL)
}

/** Heal every split on the page, including ones this run knows nothing about. */
function restoreAll() {
    document.querySelectorAll(`[${OWNED}]`).forEach(restore)
}

function dispose() {
    while (timers.length) {
        window.clearTimeout(timers.pop())
    }

    restoreAll()
}

/**
 * Take a heading apart into masked words, and optionally masked characters
 * inside them.
 *
 * Returns the nodes to animate, or null if the element is not something this can
 * put back. Refusing is the common case and it is free: the heading keeps
 * whatever the rest of the motion layer was doing with it.
 *
 * @param {HTMLElement} element
 * @param {boolean} perChar
 * @returns {?HTMLElement[]}
 */
function split(element, perChar) {
    // A child element means the content is not plain text -- an icon, a badge, a
    // Blade component. Rebuilding that from textContent would delete it.
    if (element.children.length > 0 || element.hasAttribute(OWNED)) {
        return null
    }

    const original = element.textContent
    const text = original.trim()

    if (!text || text.length > MAX_CHARS) {
        return null
    }

    // The capturing group keeps the separators, so the whitespace Blade emitted
    // -- newlines and indentation included -- goes back into the DOM untouched
    // and line breaking behaves exactly as it did before.
    const tokens = original.split(/(\s+)/)
    const words = tokens.filter((token) => token && !/^\s+$/.test(token))

    if (!words.length || words.length > MAX_WORDS) {
        return null
    }

    const fragment = document.createDocumentFragment()
    const leaves = []

    tokens.forEach((token) => {
        if (!token) {
            return
        }

        if (/^\s+$/.test(token)) {
            fragment.appendChild(document.createTextNode(token))

            return
        }

        const mask = document.createElement('span')

        // The container carries the text for assistive tech (below); every span
        // under it is decoration and says so.
        mask.setAttribute('aria-hidden', 'true')
        mask.style.cssText = MASK_CSS

        if (perChar) {
            // Array.from iterates code points, so an emoji or an astral
            // character is one leaf rather than two broken halves.
            Array.from(token).forEach((character) => {
                const leaf = document.createElement('span')

                leaf.style.cssText = LEAF_CSS
                leaf.textContent = character
                mask.appendChild(leaf)
                leaves.push(leaf)
            })
        } else {
            const leaf = document.createElement('span')

            leaf.style.cssText = LEAF_CSS
            leaf.textContent = token
            mask.appendChild(leaf)
            leaves.push(leaf)
        }

        fragment.appendChild(mask)
    })

    if (!leaves.length) {
        return null
    }

    const prior = element.getAttribute('aria-label')

    if (prior !== null) {
        element.setAttribute(LABEL, prior)
    }

    element.setAttribute(ORIGINAL, original)
    element.setAttribute(OWNED, '')

    // Splitting text destroys it for a screen reader: an <h1> of one-word spans
    // is announced word by word, or not at all. The label is the heading as it
    // was written, and it is removed again when the real text comes back.
    element.setAttribute('aria-label', text)

    element.textContent = ''
    element.appendChild(fragment)

    return leaves
}

/**
 * Hide the words below their masks and lift them back, then hand the element its
 * own text back.
 *
 * The failsafe is armed BEFORE anything is hidden, so there is no window in
 * which a throw could leave a heading unreadable.
 *
 * @param {{gsap: object, owned: Function, element: HTMLElement, leaves: HTMLElement[], duration: number, amount: number, delay: number, ease: string}} options
 */
function play({ gsap, owned, element, leaves, duration, amount, delay, ease }) {
    let settled = false

    const settle = () => {
        if (settled) {
            return
        }

        settled = true
        restore(element)
    }

    // `amount` is a total, not a per-item delay, so it has to be counted here or
    // the failsafe fires while the last words are still rising and snaps them
    // into place.
    timers.push(window.setTimeout(settle, (delay + duration + amount) * 1000 + FAILSAFE))

    // No opacity anywhere: the mask does the hiding, so the failure state of
    // this whole module is text that is present and fully opaque but displaced,
    // never text that has been faded out of existence.
    owned(() =>
        gsap.fromTo(
            leaves,
            { yPercent: RISE },
            {
                yPercent: 0,
                duration,
                delay,
                ease,
                // A total spread, so a two-word heading and a nine-word heading
                // finish in the same window and the page's arrival stays bounded.
                stagger: { amount, from: 'start' },
                onComplete: settle,
                onInterrupt: settle,
            },
        ),
    )
}

/**
 * Run `enter` the first time `element` is on screen, and only once.
 *
 * 92% of the viewport is the same line scroll.js reveals cards on, so a section
 * heading's words start rising as the card that holds them arrives rather than a
 * beat later.
 */
function onFirstView(ScrollTrigger, element, enter) {
    if (!ScrollTrigger) {
        enter()

        return
    }

    ScrollTrigger.create({ trigger: element, start: 'top 92%', once: true, onEnter: enter })
}

/**
 * The page title. The one piece of text on the page that is worth a full second.
 */
function pageHeading({ gsap, owned, ease, desktop }) {
    const heading = document.querySelector('.fi-header-heading')

    if (!heading) {
        return
    }

    // A heading that is not on screen is not worth taking apart, and its reveal
    // would be over before anyone scrolled to it. In practice the page header is
    // always at the top; this is for the layouts where it is not.
    const rect = heading.getBoundingClientRect()

    if (!rect.width || rect.top > (window.innerHeight || 0)) {
        return
    }

    const text = heading.textContent.trim()
    const words = text ? text.split(/\s+/).length : 0
    const perChar = desktop && text.length <= CHAR_MAX_CHARS && words <= CHAR_MAX_WORDS

    const leaves = split(heading, perChar)

    if (!leaves) {
        return
    }

    /*
     * entrance.js drives this same <h1>: y, opacity and a blur resolving on a
     * cold desktop load. Both effects at once is not richer, it is muddier --
     * the container fades and defocuses while the words rise, so the mask edge
     * the whole effect depends on is the one thing you cannot see. Worse, its
     * `fromTo` has already written opacity 0 inline by the time this module
     * runs, so the words would rise inside an invisible heading.
     *
     * So the h1 is claimed outright: its tweens are killed and every property
     * they set is cleared, which leaves the heading exactly as the server
     * rendered it and the words the only thing moving. This happens only after
     * the split has succeeded -- a heading this module refuses keeps entrance's
     * animation untouched.
     */
    gsap.killTweensOf(heading)
    gsap.set(heading, {
        clearProps: 'transform,opacity,filter,willChange,animation,transition',
    })

    play({
        gsap,
        owned,
        element: heading,
        leaves,
        // Per-character carries a longer spread because it has more to say; both
        // variants finish inside a second, which is the budget entrance.js works
        // to and the point past which an operator is waiting on the panel.
        duration: perChar ? 0.62 : 0.65,
        amount: perChar ? 0.3 : 0.26,
        delay: 0,
        ease,
    })
}

/**
 * Section headings, and the panel's own opt-in.
 *
 * Word-level only. At `text-base` a per-character stagger is not legible as an
 * effect, it just looks like the font failed to load.
 */
function sectionHeadings({ gsap, ScrollTrigger, MOTION, owned, ease }) {
    if (!document.querySelector('.fi-main')) {
        return
    }

    const targets = gsap.utils
        .toArray('.fi-main .fi-section-header-heading, .fi-main .vf-split')
        .filter((element) => {
            // Filament renders action modals inline inside the section that owns
            // them, so a closed modal's sections are in the document with zero
            // height. Splitting one animates something nobody can see, and the
            // modal could open halfway through.
            if (element.closest('.fi-modal')) {
                return false
            }

            return element.getBoundingClientRect().height > 0
        })
        .slice(0, MAX_SECTION_HEADINGS)

    if (!targets.length) {
        return
    }

    targets.forEach((element, index) => {
        onFirstView(ScrollTrigger, element, () => {
            if (!element.isConnected) {
                return
            }

            // Split at the moment of entering, never at boot: a heading below
            // the fold that is never scrolled to is never touched, so there is
            // no such thing as a heading left masked by a trigger that did not
            // fire.
            const leaves = split(element, false)

            if (!leaves) {
                return
            }

            play({
                gsap,
                owned,
                element,
                leaves,
                duration: 0.5,
                amount: 0.18,
                // Capped: a column of sections should read as a cascade, not as
                // a queue where the fourth card is still waiting its turn.
                delay: Math.min(index, 4) * MOTION.stagger,
                ease,
            })
        })
    })
}

/**
 * Per-word and per-character heading reveals.
 *
 * @param {{gsap: import('gsap').gsap, ScrollTrigger: object, MOTION: object}} api
 */
export function textReveal({ gsap, ScrollTrigger, MOTION }) {
    if (typeof document === 'undefined' || !document.body) {
        return null
    }

    // Undo the previous run first: cancel its failsafes and put back any heading
    // it left split. A run interrupted mid-reveal is the case this covers.
    dispose()

    const ease = ensureEase(gsap, MOTION)
    const desktop = window.matchMedia(DESKTOP).matches

    /*
     * `gsap.context()` with no argument returns the context we are currently
     * running inside -- the runtime's. A tween created later, from a
     * ScrollTrigger callback, would otherwise be born with no context at all:
     * nothing would kill it on the next navigation, and its onComplete would
     * fire into a page that had already moved on and put its text back. Routing
     * every tween through add() keeps them under the runtime's control.
     */
    const host = gsap.context()

    const owned = (create) => {
        if (host) {
            host.add(() => {
                create()
            })

            return
        }

        create()
    }

    // A navigation should hand every heading its text back at once rather than
    // leaving them split until the next boot gets around to it. `once` means the
    // listener removes itself, so repeated boots cannot stack them up.
    const onNavigating = () => dispose()

    document.addEventListener('livewire:navigating', onNavigating, { once: true })

    // gsap.context() reverts tweens; it does not remove spans we appended, timers
    // we set or listeners we bound. A nested context whose only job is to return
    // a cleanup function is how the runtime's revert() reaches this file's
    // teardown -- which matters most on a navigation, where the heading has to be
    // whole again before Livewire morphs the page underneath it.
    gsap.context(() => () => {
        document.removeEventListener('livewire:navigating', onNavigating)
        dispose()
    })

    pageHeading({ gsap, owned, ease, desktop })
    sectionHeadings({ gsap, ScrollTrigger, MOTION, owned, ease })

    return null
}
