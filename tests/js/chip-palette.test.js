/**
 * TWO-25747. The visual states of both chip controls — the payment-term chips
 * and the company-mode chips — which share one palette.
 *
 * The shipped stylesheet is parsed by jsdom's CSS parser and the cascade is
 * resolved here, so a declaration that is commented out or deleted is gone
 * before the assertion runs. jsdom cannot put an element into :hover, so the
 * state pseudo-classes are rewritten to equivalent classes in the parsed
 * selector text — same specificity weight, so the winning rule is unchanged.
 *
 * jsdom's own getComputedStyle resolves by source order and ignores
 * specificity, so it cannot answer which rule wins. The tie audit covers that:
 * for every contested property in every state it asserts one strictly most
 * specific rule, which is what makes the outcome hold when a theme stacks its
 * own stylesheet over the module's.
 *
 * Anything the resolver cannot model — an at-rule it does not understand, a
 * pseudo-class it cannot stand in for — throws rather than being skipped. A
 * resolver that silently drops a rule reports green on a chip it never saw.
 */

"use strict";

const fs = require("fs");
const path = require("path");

const STYLESHEET = path.resolve(__dirname, "../../views/css/two.css");

const WIDE = 1024;
const NARROW = 480;

const SUPPORTED_CONDITIONS = ["selector(:focus-visible)"];

/*
 * Pseudo-classes that depend on interaction state. The four the chips use are
 * rewritten to classes below; any other one reaching a chip selector would be
 * a state this resolver silently gets wrong, so it throws instead.
 */
const UNMODELLED_STATE =
  /:(hover|focus|focus-visible|focus-within|active|checked|disabled|enabled|target|link|visited|any-link|valid|invalid|placeholder-shown|autofill)\b/i;

const PSEUDO_CLASSES = [
  [":focus-visible", ".two-state-focus-visible"],
  [":hover", ".two-state-hover"],
  [":focus", ".two-state-focus"],
  [":disabled", ".two-state-disabled"],
];

const HOVER = "two-state-hover";
const FOCUS = "two-state-focus";
const FOCUS_VISIBLE = "two-state-focus-visible";
const DISABLED = "two-state-disabled";

const GREY = "rgb(227, 227, 227)";
const ACCENT = "rgb(9, 16, 48)";
const WHITE = "rgb(255, 255, 255)";

/** Anything a theme rule landing between two module rules could flip. */
const CONTESTED = [
  "borderTopWidth",
  "borderTopColor",
  "borderRadius",
  "backgroundColor",
  "color",
  "fontSize",
  "fontWeight",
  "minWidth",
  "paddingTop",
  "paddingLeft",
  "outline",
  "outlineOffset",
  "cursor",
];

const CONTROLS = [
  { label: "payment-term chip", chip: "two-term-chip" },
  { label: "company-mode chip", chip: "two-company-mode-chip" },
];

const VIEWPORTS = [
  [WIDE, "at full width"],
  [NARROW, "at the narrow breakpoint"],
];

const ACCENT_ON_GREY = [ACCENT, GREY];

/** @returns {CSSStyleSheet} the shipped stylesheet, parsed */
function loadStylesheet() {
  const style = document.createElement("style");
  style.textContent = fs.readFileSync(STYLESHEET, "utf8");
  document.head.appendChild(style);
  return document.styleSheets[document.styleSheets.length - 1];
}

/** @returns {boolean|undefined} undefined where the query is not modelled */
function mediaApplies(mediaText, width) {
  let verdict = true;
  for (const clause of mediaText.toLowerCase().split(/\s+and\s+/)) {
    const term = clause.trim();
    const max = term.match(/^\(\s*max-width\s*:\s*(\d+)px\s*\)$/);
    const min = term.match(/^\(\s*min-width\s*:\s*(\d+)px\s*\)$/);
    if (term === "all" || term === "screen") continue;
    else if (max) verdict = verdict && width <= Number(max[1]);
    else if (min) verdict = verdict && width >= Number(min[1]);
    else return undefined;
  }
  return verdict;
}

function rewritePseudoClasses(selector) {
  return PSEUDO_CLASSES.reduce(
    (text, [pseudo, klass]) => text.split(pseudo).join(klass),
    selector
  );
}

/** @returns {number[]} the (id, class, type) triple CSS orders selectors by */
function specificity(selector) {
  const flat = selector.replace(/:not\(|\)/g, " ");
  const attributes = /\[[^\]]*\]/g;
  return [
    (flat.match(/#[\w-]+/g) || []).length,
    (flat.match(/\.[\w-]+/g) || []).length + (flat.match(attributes) || []).length,
    (flat.replace(/[.#][\w-]+/g, " ").replace(attributes, " ").match(/[a-z][\w-]*/gi) || [])
      .length,
  ];
}

function compareSpecificity(a, b) {
  return a[0] - b[0] || a[1] - b[1] || a[2] - b[2];
}

/** @returns {number[]|null} the winning triple, or null where nothing matches */
function matchWeight(element, selectorText) {
  return selectorText
    .split(",")
    .map(rewritePseudoClasses)
    .map((selector) => selector.trim())
    .filter((selector) => {
      if (UNMODELLED_STATE.test(selector)) {
        throw new Error(`unmodelled pseudo-class in "${selector}"`);
      }
      // A ::pseudo-element rule paints a generated box, never the chip's own.
      return !selector.includes("::") && element.matches(selector);
    })
    .map(specificity)
    .reduce((best, weight) => (best && compareSpecificity(best, weight) >= 0 ? best : weight), null);
}

/** @returns {{rule: CSSStyleRule, order: number, weight: number[]}[]} matches, weakest first */
function matchingRules(element, width) {
  const matches = [];
  let order = 0;

  const walk = (rules, live) => {
    for (const rule of Array.from(rules)) {
      if (rule.cssRules && rule.media) {
        const applies = mediaApplies(rule.media.mediaText, width);
        if (applies === undefined) reject(rule, `@media ${rule.media.mediaText}`);
        walk(rule.cssRules, live && applies);
      } else if (rule.cssRules && rule.conditionText !== undefined) {
        if (!SUPPORTED_CONDITIONS.includes(rule.conditionText)) {
          reject(rule, `@supports ${rule.conditionText}`);
        }
        walk(rule.cssRules, live);
      } else if (rule.cssRules) {
        reject(rule, rule.cssText.split("{")[0].trim());
      } else if (rule.selectorText) {
        const weight = live ? matchWeight(element, rule.selectorText) : null;
        if (weight) matches.push({ rule, order: (order += 1), weight });
      }
    }
  };

  const reject = (rule, description) => {
    const reaches = Array.from(rule.cssRules).some(
      (inner) => inner.selectorText && matchWeight(element, inner.selectorText)
    );
    if (reaches) throw new Error(`chip rule inside unmodelled ${description}`);
  };

  walk(loadStylesheet().cssRules, true);
  return matches.sort(
    (a, b) => compareSpecificity(a.weight, b.weight) || a.order - b.order
  );
}

function chipElement(classes) {
  const element = document.createElement("button");
  element.className = classes.join(" ");
  document.body.appendChild(element);
  return element;
}

function styleOf(cssText) {
  const probe = document.createElement("div");
  probe.setAttribute("style", cssText);
  return probe.style;
}

function toRgb(value) {
  const colour = styleOf(`color: ${value}`).color;
  const hex = colour.match(/^#([0-9a-f]{3}|[0-9a-f]{6})$/i);
  if (!hex) return colour === "white" ? WHITE : colour;
  const digits = hex[1].length === 3 ? hex[1].replace(/./g, (d) => d + d) : hex[1];
  const n = parseInt(digits, 16);
  return `rgb(${(n >> 16) & 255}, ${(n >> 8) & 255}, ${n & 255})`;
}

/**
 * Resolves the cascade for a chip carrying `classes` at viewport `width`.
 *
 * @returns {CSSStyleDeclaration} the chip's declared style
 */
function chipStyle(classes, width) {
  const winners = matchingRules(chipElement(classes), width || WIDE);
  return styleOf(winners.map((match) => match.rule.style.cssText).join(" "));
}

/** @returns {number[]} the chip's outer width and height, in px, sans content */
function outerBox(classes, width) {
  const style = chipStyle(classes, width);
  return [
    2 * parseFloat(style.borderLeftWidth) +
      parseFloat(style.paddingLeft) +
      parseFloat(style.paddingRight),
    2 * parseFloat(style.borderTopWidth) +
      parseFloat(style.paddingTop) +
      parseFloat(style.paddingBottom),
  ];
}

/** @returns {string[]} `${property} @ ${weight}` for every property two rules contest */
function positionalTies(classes, width) {
  const matches = matchingRules(chipElement(classes), width || WIDE);
  return CONTESTED.flatMap((property) => {
    const declaring = matches.filter(
      (match) => styleOf(match.rule.style.cssText)[property] !== ""
    );
    if (declaring.length < 2) return [];
    const top = declaring
      .map((match) => match.weight)
      .reduce((best, weight) => (compareSpecificity(best, weight) >= 0 ? best : weight));
    const contenders = declaring.filter(
      (match) => compareSpecificity(match.weight, top) === 0
    );
    return contenders.length > 1
      ? [`${property} @ ${top}: ${contenders.map((c) => c.rule.selectorText)}`]
      : [];
  });
}

afterEach(() => {
  document.head.innerHTML = "";
  document.body.innerHTML = "";
});

describe.each(CONTROLS)("$label", ({ chip }) => {
  const resting = [chip];
  const selected = [chip, `${chip}--selected`];
  const hovered = [chip, HOVER];
  const focused = [chip, FOCUS];
  const engaged = [chip, HOVER, FOCUS];
  const hoveredSelected = [chip, `${chip}--selected`, HOVER];
  const focusedSelected = [chip, `${chip}--selected`, FOCUS];
  const engagedSelected = [chip, `${chip}--selected`, HOVER, FOCUS];
  const ringed = [chip, FOCUS, FOCUS_VISIBLE];
  const ringedSelected = [chip, `${chip}--selected`, FOCUS, FOCUS_VISIBLE];

  const states = [
    [resting, "2px", GREY, WHITE, "resting: grey outline on white"],
    [selected, "2px", ACCENT, ACCENT, "selected: solid accent fill"],
    [hovered, "1px", ...ACCENT_ON_GREY, "hovered: thin accent on grey"],
    [focused, "1px", ...ACCENT_ON_GREY, "focused: same as hovered"],
    [engaged, "1px", ...ACCENT_ON_GREY, "hovered and focused: same as either"],
    [hoveredSelected, "2px", ACCENT, ACCENT, "hovered while selected: no change"],
    [focusedSelected, "2px", ACCENT, ACCENT, "focused while selected: no change"],
    [engagedSelected, "2px", ACCENT, ACCENT, "both while selected: no change"],
    [ringed, "1px", ...ACCENT_ON_GREY, "showing the keyboard focus ring"],
    [ringedSelected, "2px", ACCENT, ACCENT, "ringed while selected"],
  ];

  test.each(
    VIEWPORTS.flatMap(([width, viewport]) =>
      states.map((state) => [...state, width, viewport])
    )
  )(
    "%s %#",
    (classes, borderWidth, borderColor, background, description, width) => {
      const style = chipStyle(classes, width);

      expect([
        style.borderTopWidth,
        toRgb(style.borderTopColor),
        toRgb(style.backgroundColor),
      ]).toEqual([borderWidth, borderColor, background], description);
    }
  );

  test.each(
    VIEWPORTS.flatMap(([width, viewport]) =>
      states.map(([classes, , , , description]) => [classes, width, description, viewport])
    )
  )("%s is decided by specificity, not source position %#", (classes, width) => {
    expect(positionalTies(classes, width)).toEqual([]);
  });

  test.each([
    [selected, "selected"],
    [hoveredSelected, "selected and hovered"],
    [focusedSelected, "selected and focused"],
    [engagedSelected, "selected, hovered and focused"],
    [ringedSelected, "selected and showing the focus ring"],
  ])("the label stays legible on the accent fill when %s", (classes) => {
    expect(toRgb(chipStyle(classes).color)).toBe(WHITE);
  });

  test.each(
    VIEWPORTS.flatMap(([width, viewport]) => [
      [hovered, width, `hover ${viewport}`],
      [focused, width, `focus ${viewport}`],
      [selected, width, `selection ${viewport}`],
    ])
  )("the chip does not resize on %#", (classes, width) => {
    expect(outerBox(classes, width)).toEqual(outerBox(resting, width));
  });

  test("the label weight is constant across every state", () => {
    const weights = states.map(([classes]) => chipStyle(classes).fontWeight);

    expect(new Set(weights).size).toBe(1);
  });

  test("no state carries a colour the palette retired", () => {
    const retired = ["#d1d5db", "#f9fafb", "#9ca3af", "#374151"].map(toRgb);
    const seen = states
      .flatMap(([classes]) => {
        const style = chipStyle(classes);
        return [style.borderTopColor, style.backgroundColor, style.color];
      })
      .map(toRgb);

    expect(seen.filter((colour) => retired.includes(colour))).toEqual([]);
  });

  test.each([
    [[chip, DISABLED, HOVER], "hovered"],
    [[chip, DISABLED, FOCUS], "focused"],
  ])("a disabled chip keeps its resting appearance when %s", (classes) => {
    const style = chipStyle(classes);

    expect([
      style.borderTopWidth,
      toRgb(style.borderTopColor),
      toRgb(style.backgroundColor),
      ...outerBox(classes),
    ]).toEqual(["2px", GREY, WHITE, ...outerBox(resting)]);
  });

  test("a chip focused by pointer shows no ring", () => {
    expect(chipStyle(focused).outline).toBe("none");
  });

  test.each([
    [ringed, "unselected"],
    [ringedSelected, "selected"],
  ])("the keyboard focus ring is the accent on a %s chip", (classes) => {
    const [width, , color] = chipStyle(classes).outline.split(/\s+/);

    expect([width, toRgb(color)]).toEqual(["2px", ACCENT]);
  });
});

/*
 * Guards the viewport modelling itself: a media query this resolver stopped
 * selecting would drop every narrow assertion above to a duplicate of its
 * full-width twin, silently.
 */
test("the narrow breakpoint reaches the payment-term chip", () => {
  const chip = ["two-term-chip"];

  expect(chipStyle(chip, NARROW).paddingTop).not.toBe(
    chipStyle(chip, WIDE).paddingTop
  );
});

/* A sole term chip is the only chip this module ever marks disabled. */
describe("a disabled payment-term chip", () => {
  const CHIP = "two-term-chip";
  const SELECTED = `${CHIP}--selected`;
  const SINGLE = `${CHIP}--single`;

  const states = [
    [
      [CHIP, SINGLE, SELECTED, DISABLED, HOVER],
      [ACCENT, ACCENT],
      [CHIP, SELECTED],
      "hovered while selected",
    ],
    [
      [CHIP, SINGLE, SELECTED, DISABLED, FOCUS],
      [ACCENT, ACCENT],
      [CHIP, SELECTED],
      "focused while selected",
    ],
  ];

  test.each(
    VIEWPORTS.flatMap(([width]) => states.map((state) => [...state, width]))
  )("keeps its resting appearance when %#", (classes, colours, atRest, description, width) => {
    const style = chipStyle(classes, width);

    expect([
      style.borderTopWidth,
      toRgb(style.borderTopColor),
      toRgb(style.backgroundColor),
      ...outerBox(classes, width),
    ]).toEqual(["2px", ...colours, ...outerBox(atRest, width)], description);
  });

  test.each(
    VIEWPORTS.flatMap(([width]) => states.map(([classes]) => [classes, width]))
  )("%s is decided by specificity, not source position %#", (classes, width) => {
    expect(positionalTies(classes, width)).toEqual([]);
  });
});
