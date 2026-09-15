/**
 * TWO-25747. The visual states of both chip controls — the payment-term chips
 * and the company-mode chips — which share one palette.
 *
 * jsdom cannot put an element into :hover and its getComputedStyle ignores
 * specificity, so states are rewritten to classes and the cascade resolved here.
 *
 * Known gaps, each confirmed by watching the resolver answer rather than by
 * reading its intent. This list is what has been found; it is not a proof that
 * nothing else gets through.
 *
 *  - An at-rule wrapper around an otherwise-correct rule is invisible, so
 *    unwrapping the @supports around the focus-ring reset does not fail here.
 *  - Only the two viewports below are audited. A chip rule in, say,
 *    @media (min-width: 2000px) is modelled, found not to apply at either, and
 *    so never evaluated at all.
 *  - A ::pseudo-element rule is dropped on the grounds that it paints a
 *    generated box; one positioned over the chip would not be caught.
 *  - The probe strip holds one chip, so a sibling combinator matches nothing
 *    and is dropped rather than rejected.
 *  - A mode chip carries one of three identity classes alongside the shared
 *    one; the probe carries only the shared one.
 */

"use strict";

const fs = require("fs");
const path = require("path");

const STYLESHEET = path.resolve(__dirname, "../../views/css/two.css");

const WIDE = 1024;
const NARROW = 480;

const SUPPORTED_CONDITIONS = ["selector(:focus-visible)"];

const MODELLED_PSEUDO = /^:(not|is|where)\(/;

const NESTED_FUNCTIONAL = /:(not|is|where)\([^)]*:[a-z][\w-]*\(/i;

/* Anchored: an unanchored ":focus" also eats the ":focus" of ":focus-within". */
const PSEUDO_CLASSES = [
  [/:focus-visible(?![-\w])/g, ".two-state-focus-visible"],
  [/:hover(?![-\w])/g, ".two-state-hover"],
  [/:focus(?![-\w])/g, ".two-state-focus"],
  [/:disabled(?![-\w])/g, ".two-state-disabled"],
];

const STYLE_RULE = 1;
const IMPORT_RULE = 3;
const MEDIA_RULE = 4;
const KEYFRAMES_RULE = 7;
const SUPPORTS_RULE = 12;

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

/* A selector keyed on an ancestor or an attribute reaches the probe only if
   the probe mirrors the shipped DOM. */
const CONTROLS = [
  {
    label: "payment-term chip",
    chip: "two-term-chip",
    strip:
      '<div class="two-payment-terms" id="two-payment-terms">' +
      '<div class="two-term-chips">' +
      '<div class="two-term-chips__container" id="two-terms-chips" role="radiogroup"' +
      ' aria-labelledby="two-terms-title"></div>' +
      "</div></div>",
    slot: ".two-term-chips__container",
    attributes: { type: "button", role: "radio", "data-days": "30" },
    contents:
      '<span class="two-term-chip__days">30 days</span>' +
      '<span class="two-term-chip__surcharge">' +
      '<span class="two-term-chip__loading" aria-hidden="true">' +
      "<span>.</span><span>.</span><span>.</span></span></span>",
  },
  {
    label: "company-mode chip",
    chip: "two-company-mode-chip",
    strip:
      '<div class="two-company-field-wrap">' +
      '<div class="two-company-dropdown">' +
      '<div class="two-company-mode-chips"></div>' +
      "</div></div>",
    slot: ".two-company-mode-chips",
    attributes: { type: "button" },
    contents: "Registered company",
  },
];

const CONTROL_BY_CHIP = new Map(CONTROLS.map((control) => [control.chip, control]));

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
  const query = mediaText.toLowerCase().replace(/^\s*only\s+/, "");
  for (const clause of query.split(/\s+and\s+/)) {
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
    (text, [pseudo, klass]) => text.replace(pseudo, klass),
    selector
  );
}

function closingParen(text, open) {
  let depth = 0;
  for (let index = open; index < text.length; index += 1) {
    if (text[index] === "(") depth += 1;
    else if (text[index] === ")" && (depth -= 1) === 0) return index;
  }
  return text.length;
}

/** @returns {string[]} `text` split on its top-level commas */
function splitArguments(text) {
  const parts = [];
  let depth = 0;
  let start = 0;
  for (let index = 0; index < text.length; index += 1) {
    if (text[index] === "(") depth += 1;
    else if (text[index] === ")") depth -= 1;
    else if (text[index] === "," && depth === 0) {
      parts.push(text.slice(start, index));
      start = index + 1;
    }
  }
  return parts.concat(text.slice(start));
}

function addWeights(a, b) {
  return [a[0] + b[0], a[1] + b[1], a[2] + b[2]];
}

/** @returns {number[]} the triple for a selector carrying no :is/:where/:not */
function simpleSpecificity(selector) {
  const attributes = /\[[^\]]*\]/g;
  const bare = selector.replace(attributes, " ");
  return [
    (selector.match(/#[\w-]+/g) || []).length,
    (selector.match(/\.[\w-]+/g) || []).length +
      (selector.match(attributes) || []).length +
      (bare.match(/:[a-z][\w-]*/gi) || []).length,
    (bare.replace(/[.#:][\w-]+/g, " ").match(/[a-z][\w-]*/gi) || []).length,
  ];
}

/** @returns {number[]} the (id, class, type) triple CSS orders selectors by */
function specificity(selector) {
  let rest = selector;
  let total = [0, 0, 0];
  let functional = /:(is|where|not)\(/i.exec(rest);
  // :is() and :not() contribute their most specific argument and :where()
  // contributes nothing, so neither list may be counted where it stands.
  while (functional) {
    const open = functional.index + functional[0].length - 1;
    const close = closingParen(rest, open);
    if (functional[1].toLowerCase() !== "where") {
      total = addWeights(
        total,
        splitArguments(rest.slice(open + 1, close))
          .map(specificity)
          .reduce(
            (best, weight) => (compareSpecificity(best, weight) >= 0 ? best : weight),
            [0, 0, 0]
          )
      );
    }
    rest = `${rest.slice(0, functional.index)} ${rest.slice(close + 1)}`;
    functional = /:(is|where|not)\(/i.exec(rest);
  }
  return addWeights(total, simpleSpecificity(rest));
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
      // A ::pseudo-element rule paints a generated box, never the chip's own.
      if (selector.includes("::")) return false;
      const unmodelled = (selector.match(/:[a-z][\w-]*\(?/gi) || []).filter(
        (pseudo) => !MODELLED_PSEUDO.test(pseudo)
      );
      // nwsapi answers a nested functional pseudo-class with a flat false.
      if (NESTED_FUNCTIONAL.test(selector)) unmodelled.push("nested :is()/:where()/:not()");
      if (unmodelled.length === 0) return element.matches(selector);
      if (reachesUnderAnyState(element, selector)) {
        throw new Error(`unmodelled ${unmodelled[0]} in "${selector}"`);
      }
      return false;
    })
    .map(specificity)
    .reduce((best, weight) => (best && compareSpecificity(best, weight) >= 0 ? best : weight), null);
}

/** @returns {boolean} whether the selector could reach the element in some unmodelled state */
function reachesUnderAnyState(element, selector) {
  // Dropping a constraint only widens what the selector could reach.
  const residue = selector.replace(/:[a-z][\w-]*(\([^)]*\))?/gi, "").trim();
  try {
    return residue === "" || element.matches(residue);
  } catch (invalidResidue) {
    return true;
  }
}

/* Keywords the `animation` shorthand carries in a name's place; the
   `animation-name` longhand takes none of them. */
const SHORTHAND_KEYWORDS =
  /^(infinite|normal|reverse|alternate|alternate-reverse|forwards|backwards|both|running|paused|linear|ease|ease-in|ease-out|ease-in-out|step-start|step-end)$/i;

const NEVER_A_NAME = /^(none|initial|inherit|unset|revert|revert-layer)$/i;

/** @returns {{name: string, ambiguous: boolean}[]} the animations a rule references */
function animationNames(cssText) {
  return (cssText.match(/animation(-name)?\s*:[^;]*/gi) || []).flatMap((declaration) => {
    const longhand = /animation-name/i.test(declaration);
    if (/var\s*\(/i.test(declaration)) {
      throw new Error(`chip animation resolves through var(): ${declaration.trim()}`);
    }
    return declaration
      .slice(declaration.indexOf(":") + 1)
      // Durations, delays, counts and timing functions are not identifiers.
      .replace(/[a-z-]+\([^)]*\)/gi, " ")
      .replace(/[-+]?\d*\.?\d+(m?s|%)?/gi, " ")
      .split(",")
      .flatMap((slot) => slot.match(/-?[a-z_][\w-]*/gi) || [])
      .filter((token) => !NEVER_A_NAME.test(token))
      .map((name) => ({ name, ambiguous: !longhand && SHORTHAND_KEYWORDS.test(name) }));
  });
}

/** @returns {{rule: CSSStyleRule, order: number, weight: number[]}[]} matches, weakest first */
function matchingRules(element, width) {
  const matches = [];
  const keyframes = new Map();
  let order = 0;

  const reject = (rule, description) => {
    if (reachesChip(rule)) {
      throw new Error(`chip rule inside unmodelled ${description}`);
    }
  };

  const reachesChip = (rule) => {
    if (rule.selectorText) return Boolean(matchWeight(element, rule.selectorText));
    return rule.cssRules ? Array.from(rule.cssRules).some(reachesChip) : false;
  };

  const walk = (rules, live) => {
    for (const rule of Array.from(rules)) {
      if (rule.type === STYLE_RULE) {
        const weight = live ? matchWeight(element, rule.selectorText) : null;
        if (weight) matches.push({ rule, order: (order += 1), weight });
      } else if (rule.type === MEDIA_RULE) {
        const applies = mediaApplies(rule.media.mediaText, width);
        if (applies === undefined) {
          reject(rule, `@media ${rule.media.mediaText}`);
        } else {
          walk(rule.cssRules, live && applies);
        }
      } else if (rule.type === SUPPORTS_RULE) {
        if (SUPPORTED_CONDITIONS.includes(rule.conditionText)) {
          walk(rule.cssRules, live);
        } else {
          reject(rule, `@supports ${rule.conditionText}`);
        }
      } else if (rule.type === IMPORT_RULE) {
        // Its sheet is a second cascade this resolver never reads.
        throw new Error(`unmodelled @import of ${rule.href}`);
      } else if (rule.type === KEYFRAMES_RULE) {
        // An animation outranks every author declaration and `forwards` keeps
        // its last frame, so a block a chip rule names may declare nothing.
        const declared = new Set();
        for (const frame of Array.from(rule.cssRules)) {
          for (const property of Array.from(frame.style)) declared.add(property);
        }
        keyframes.set(rule.name, Array.from(declared));
      } else {
        reject(rule, rule.cssText.split("{")[0].trim());
        throw new Error(`unmodelled rule type ${rule.type}`);
      }
    }
  };

  walk(loadStylesheet().cssRules, true);

  for (const match of matches) {
    for (const { name, ambiguous } of animationNames(match.rule.style.cssText)) {
      // In the shorthand a keyword wins its slot, so such a token is only a
      // name when the sheet defines one — and then which it is cannot be told.
      if (ambiguous && !keyframes.has(name)) continue;
      if (!keyframes.has(name)) {
        throw new Error(`chip animation "${name}" has no keyframes in this sheet`);
      }
      const touches = keyframes.get(name);
      if (touches.length) {
        throw new Error(`chip animation "${name}" sets ${touches.join(", ")}`);
      }
    }
  }

  return matches.sort(
    (a, b) => compareSpecificity(a.weight, b.weight) || a.order - b.order
  );
}

function chipElement(classes) {
  const control = CONTROL_BY_CHIP.get(classes[0]);
  const holder = document.createElement("div");
  holder.innerHTML = control.strip;
  const strip = holder.firstElementChild;
  document.body.appendChild(strip);

  const element = document.createElement("button");
  element.className = classes.join(" ");
  for (const [name, value] of Object.entries(control.attributes)) {
    element.setAttribute(name, value);
  }
  element.innerHTML = control.contents;

  const selected = classes.includes(`${control.chip}--selected`);
  if (element.getAttribute("role") === "radio") {
    element.setAttribute("aria-checked", String(selected));
    element.tabIndex = selected ? 0 : -1;
  }
  if (classes.includes(DISABLED)) {
    element.disabled = true;
    element.setAttribute("aria-disabled", "true");
  }

  strip.querySelector(control.slot).appendChild(element);
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

/** @returns {CSSStyleDeclaration} the chip's declared style */
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

const dashed = (property) => property.replace(/[A-Z]/g, (letter) => `-${letter.toLowerCase()}`);

/** @returns {string[]} `${property} @ ${weight}` for every property two rules contest */
function positionalTies(classes, width) {
  const matches = matchingRules(chipElement(classes), width || WIDE);
  return CONTESTED.flatMap((property) => {
    const declaring = matches.filter(
      (match) => styleOf(match.rule.style.cssText)[property] !== ""
    );
    // !important outranks specificity outright, so where one is declared the
    // contest is among the important declarations alone.
    const important = declaring.filter(
      (match) =>
        styleOf(match.rule.style.cssText).getPropertyPriority(dashed(property)) === "important"
    );
    const tier = important.length ? important : declaring;
    if (tier.length < 2) return [];
    const top = tier
      .map((match) => match.weight)
      .reduce((best, weight) => (compareSpecificity(best, weight) >= 0 ? best : weight));
    const contenders = tier.filter(
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

  test.each(VIEWPORTS)("an unselected label is the accent in every state %s", (width) => {
    const atRest = toRgb(chipStyle(resting, width).color);

    expect(atRest).toBe(ACCENT);
    expect([
      toRgb(chipStyle(hovered, width).color),
      toRgb(chipStyle(focused, width).color),
      toRgb(chipStyle(engaged, width).color),
      toRgb(chipStyle(ringed, width).color),
    ]).toEqual([atRest, atRest, atRest, atRest]);
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
