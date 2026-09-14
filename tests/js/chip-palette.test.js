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
 * specificity, so it cannot answer which rule wins. The tie audit at the foot
 * of this file covers that: for every contested property in every state it
 * asserts one strictly highest-specificity rule, which is what makes the
 * outcome hold when a theme stacks its own stylesheet over the module's.
 */

"use strict";

const fs = require("fs");
const path = require("path");

const STYLESHEET = path.resolve(__dirname, "../../views/css/two.css");
const NARROW_MEDIA = "(max-width: 768px)";

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
  "backgroundColor",
  "color",
  "paddingTop",
  "paddingLeft",
  "outline",
  "cursor",
];

const CONTROLS = [
  { label: "payment-term chip", chip: "two-term-chip" },
  { label: "company-mode chip", chip: "two-company-mode-chip" },
];

const ACCENT_ON_GREY = [ACCENT, GREY];

/** @returns {CSSStyleSheet} the shipped stylesheet, parsed */
function loadStylesheet() {
  const style = document.createElement("style");
  style.textContent = fs.readFileSync(STYLESHEET, "utf8");
  document.head.appendChild(style);
  return document.styleSheets[document.styleSheets.length - 1];
}

/** @returns {CSSStyleRule[]} style rules in document order, narrow ones spliced in place */
function flatten(rules, narrow) {
  const flat = [];
  for (const rule of Array.from(rules)) {
    if (rule.media) {
      if (narrow && rule.media.mediaText === NARROW_MEDIA) {
        flat.push(...flatten(rule.cssRules, narrow));
      }
    } else if (rule.selectorText) {
      flat.push(rule);
    }
  }
  return flat;
}

function rewritePseudoClasses(selector) {
  return PSEUDO_CLASSES.reduce(
    (text, [pseudo, klass]) => text.split(pseudo).join(klass),
    selector
  );
}

/** @returns {number} ids weigh a hundred; classes, attributes and states one each */
function specificity(selector) {
  return (
    100 * (selector.match(/#/g) || []).length +
    (selector.match(/[.[]/g) || []).length
  );
}

/** @returns {{rule: CSSStyleRule, order: number, weight: number}[]} matches, weakest first */
function matchingRules(element, options) {
  const narrow = Boolean(options && options.narrow);
  return flatten(loadStylesheet().cssRules, narrow)
    .map((rule, order) => {
      const weights = rule.selectorText
        .split(",")
        .map(rewritePseudoClasses)
        .map((selector) => selector.trim())
        .filter((selector) => element.matches(selector))
        .map(specificity);
      return { rule, order, weight: Math.max(-1, ...weights) };
    })
    .filter((match) => match.weight >= 0)
    .sort((a, b) => a.weight - b.weight || a.order - b.order);
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
 * Resolves the cascade for a chip carrying `classes`.
 *
 * @returns {CSSStyleDeclaration} the chip's declared style
 */
function chipStyle(classes, options) {
  const winners = matchingRules(chipElement(classes), options);
  return styleOf(winners.map((match) => match.rule.style.cssText).join(" "));
}

/** @returns {number[]} the chip's outer width and height, in px, sans content */
function outerBox(classes, options) {
  const style = chipStyle(classes, options);
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
function positionalTies(classes) {
  const matches = matchingRules(chipElement(classes));
  return CONTESTED.flatMap((property) => {
    const declaring = matches.filter(
      (match) => styleOf(match.rule.style.cssText)[property] !== ""
    );
    if (declaring.length < 2) return [];
    const top = Math.max(...declaring.map((match) => match.weight));
    const contenders = declaring.filter((match) => match.weight === top);
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

  test.each([
    [resting, "2px", GREY, WHITE, "resting: grey outline on white"],
    [selected, "2px", ACCENT, ACCENT, "selected: solid accent fill"],
    [hovered, "1px", ...ACCENT_ON_GREY, "hovered: thin accent on grey"],
    [focused, "1px", ...ACCENT_ON_GREY, "focused: same as hovered"],
    [engaged, "1px", ...ACCENT_ON_GREY, "hovered and focused: same as either"],
    [hoveredSelected, "2px", ACCENT, ACCENT, "hovered while selected: no change"],
    [focusedSelected, "2px", ACCENT, ACCENT, "focused while selected: no change"],
    [engagedSelected, "2px", ACCENT, ACCENT, "both while selected: no change"],
  ])("%s", (classes, borderWidth, borderColor, background, description) => {
    const style = chipStyle(classes);

    expect([
      style.borderTopWidth,
      toRgb(style.borderTopColor),
      toRgb(style.backgroundColor),
    ]).toEqual([borderWidth, borderColor, background], description);
  });

  test.each([
    [selected, "selected"],
    [hoveredSelected, "selected and hovered"],
    [focusedSelected, "selected and focused"],
    [engagedSelected, "selected, hovered and focused"],
  ])("the label stays legible on the accent fill when %s", (classes) => {
    expect(toRgb(chipStyle(classes).color)).toBe(WHITE);
  });

  test.each([
    [hovered, {}, "hover at full width"],
    [focused, {}, "focus at full width"],
    [hovered, { narrow: true }, "hover at the narrow breakpoint"],
    [focused, { narrow: true }, "focus at the narrow breakpoint"],
  ])("the chip does not resize on %#", (classes, options) => {
    expect(outerBox(classes, options)).toEqual(outerBox(resting, options));
  });

  test("no state carries a colour the palette retired", () => {
    const retired = ["#d1d5db", "#f9fafb", "#9ca3af", "#374151"].map(toRgb);
    const seen = [resting, selected, hovered, focused, hoveredSelected]
      .flatMap((classes) => {
        const style = chipStyle(classes);
        return [style.borderTopColor, style.backgroundColor, style.color];
      })
      .map(toRgb);

    expect(seen.filter((colour) => retired.includes(colour))).toEqual([]);
  });

  test("a chip focused by pointer shows no ring", () => {
    expect(chipStyle(focused).outline).toBe("none");
  });

  test("the keyboard focus ring is the accent", () => {
    const [width, , color] = chipStyle(ringed).outline.split(/\s+/);

    expect([width, toRgb(color)]).toEqual(["2px", ACCENT]);
  });

  /*
   * A PrestaShop theme stacks its own stylesheet over the module's, so a state
   * whose winner is only the later of two equally specific rules renders
   * differently the moment anything lands between them.
   */
  test.each([
    [resting, "resting"],
    [selected, "selected"],
    [hovered, "hovered"],
    [focused, "focused"],
    [engaged, "hovered and focused"],
    [hoveredSelected, "hovered while selected"],
    [focusedSelected, "focused while selected"],
    [engagedSelected, "hovered and focused while selected"],
    [ringed, "showing the keyboard focus ring"],
  ])("%s is decided by specificity, not source position", (classes) => {
    expect(positionalTies(classes)).toEqual([]);
  });
});

/* A sole term chip is the only chip this module ever marks disabled. */
describe("a disabled payment-term chip", () => {
  const CHIP = "two-term-chip";
  const SELECTED = `${CHIP}--selected`;
  const SINGLE = `${CHIP}--single`;

  test.each([
    [[CHIP, DISABLED, HOVER], [GREY, WHITE], [CHIP], "hovered"],
    [[CHIP, DISABLED, FOCUS], [GREY, WHITE], [CHIP], "focused"],
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
  ])("keeps its resting appearance when %#", (classes, colours, atRest) => {
    const style = chipStyle(classes);

    expect([
      style.borderTopWidth,
      toRgb(style.borderTopColor),
      toRgb(style.backgroundColor),
      ...outerBox(classes),
    ]).toEqual(["2px", ...colours, ...outerBox(atRest)]);
  });

  test.each([
    [[CHIP, SINGLE, SELECTED, DISABLED], "at rest"],
    [[CHIP, SINGLE, SELECTED, DISABLED, HOVER], "hovered"],
  ])("%s is decided by specificity, not source position", (classes) => {
    expect(positionalTies(classes)).toEqual([]);
  });
});
