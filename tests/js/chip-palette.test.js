/**
 * TWO-25747. The visual states of both chip controls — the payment-term chips
 * and the company-mode chips — which share one palette.
 *
 * jsdom cannot put an element into :hover and its getComputedStyle ignores
 * specificity, so states are rewritten to classes and the cascade resolved here.
 *
 * Whether a rule reaches a chip is decided from the selector's own vocabulary,
 * evaluated against a chain of the chip and its ancestors. That chain is READ
 * OFF the markup the shipped renderers produce under jsdom, over several
 * document shapes each, so a tag, class or attribute those renderers vary is
 * seen to vary rather than frozen from one render. Nothing about the chain is
 * written down here, so it cannot drift from the markup it stands for. A fact
 * the chain does not hold is rejected, never assumed: an attribute key no
 * render carried, a presence or a value that varies, an unmodelled
 * pseudo-class, a sibling combinator — unless another simple selector in the
 * same compound has already ruled the chip out.
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
 *  - The chain starts at the chip's own strip, so a selector keyed on a theme
 *    element above it is dropped rather than rejected.
 *  - Attribute values are compared case-insensitively whichever attribute they
 *    belong to, so a rule keyed on a case-sensitive one can be over-applied.
 *  - The chip's own state classes are the case under test rather than a render.
 *    Only their disabled/selected combination is checked against the renders.
 */

"use strict";

const fs = require("fs");
const path = require("path");

const {
  buildAddressForm,
  loadCompanySearch,
  loadOrderIntent,
  loadScript,
  openPanel,
  releaseWidgets,
  stubAjax,
} = require("./ps-harness");

const STYLESHEET = path.resolve(__dirname, "../../views/css/two.css");

const CHECKOUT_HOST = "https://api.example.test";

const WIDE = 1024;
const NARROW = 480;

const SUPPORTED_CONDITIONS = ["selector(:focus-visible)"];

const FUNCTIONAL_PSEUDO = /^:(not|is|where)\((.*)\)$/i;

const COMBINATORS = new Set([">", "+", "~"]);

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

const CONTROLS = [
  {
    label: "payment-term chip",
    chip: "two-term-chip",
    strip: "two-payment-terms",
    render: renderTermChips,
  },
  {
    label: "company-mode chip",
    chip: "two-company-mode-chip",
    strip: "two-company-field-wrap",
    render: renderModeChips,
  },
];

const VIEWPORTS = [
  [WIDE, "at full width"],
  [NARROW, "at the narrow breakpoint"],
];

const ACCENT_ON_GREY = [ACCENT, GREY];

/** Per chip class: the merged chain, and the chip states the renders showed. */
const MODEL = new Map();

/** @returns {object[]} the chip and its ancestors up to `strip`, outermost first */
function snapshotChain(chip, strip) {
  const chain = [];
  for (let node = chip; node; node = node.parentElement) {
    const attributes = {};
    for (const attribute of Array.from(node.attributes)) {
      attributes[attribute.name] = attribute.value;
    }
    chain.unshift({
      tag: node.tagName.toLowerCase(),
      classes: Array.from(node.classList),
      attributes,
    });
    if (node.classList.contains(strip)) return chain;
  }
  throw new Error(`no .${strip} above the chip`);
}

/** @returns {object[][]} a chain per chip now on the page */
function snapshotChips(control) {
  return Array.from(document.querySelectorAll(`.${control.chip}`)).map((chip) =>
    snapshotChain(chip, control.strip)
  );
}

function checkoutManager(terms) {
  return new window.TwoCheckoutManager({
    checkoutHost: CHECKOUT_HOST,
    orderIntentEnabled: false,
    ajaxToken: "test-token",
    available_payment_terms: terms,
    default_payment_term: terms[0],
  });
}

function renderTermChips(control) {
  const chains = [];
  // The strip as injectPaymentTermsIfMissing() leaves it, taken before
  // initializePaymentTerms() writes the group's own attributes onto it, so the
  // title can be removed and the theme-supplied-container branch driven.
  let strip = null;
  const initialize = window.TwoCheckoutManager.prototype.initializePaymentTerms;
  window.TwoCheckoutManager.prototype.initializePaymentTerms = function () {
    const built = document.querySelector(`.${control.strip}`);
    if (built && !strip) strip = built.cloneNode(true);
    return initialize.apply(this, arguments);
  };
  try {
    document.body.innerHTML = '<div class="two-payment-info"></div>';
    const several = checkoutManager([14, 30, 45, 60]);
    several.injectPaymentTermsIfMissing();
    chains.push(...snapshotChips(control));
    several.showPaymentTerms();
    chains.push(...snapshotChips(control));

    document.body.innerHTML = '<div class="two-payment-info"></div>';
    checkoutManager([30]).injectPaymentTermsIfMissing();
    chains.push(...snapshotChips(control));

    document.body.innerHTML = "";
    strip.querySelector("#two-terms-title").remove();
    document.body.appendChild(strip);
    checkoutManager([14, 30, 45, 60]).initializePaymentTerms();
    chains.push(...snapshotChips(control));
  } finally {
    window.TwoCheckoutManager.prototype.initializePaymentTerms = initialize;
  }
  return chains;
}

function renderModeChips(control) {
  buildAddressForm({ country: "GB" });
  new window.TwoCompanySearch({ checkoutHost: CHECKOUT_HOST }).init();
  const closed = snapshotChips(control);
  openPanel();
  return closed.concat(snapshotChips(control));
}

/** @returns {object} one node answering for every render it was seen in */
function mergeNodes(nodes) {
  const always = nodes[0].classes.filter((name) =>
    nodes.every((node) => node.classes.includes(name))
  );
  const ever = new Set(nodes.flatMap((node) => node.classes));
  const attributes = new Map();
  for (const key of new Set(nodes.flatMap((node) => Object.keys(node.attributes)))) {
    const held = nodes.filter((node) => key in node.attributes);
    attributes.set(key, {
      everywhere: held.length === nodes.length,
      values: new Set(held.map((node) => node.attributes[key])),
    });
  }
  return {
    tags: new Set(nodes.map((node) => node.tag)),
    classes: always,
    varying: new Set(Array.from(ever).filter((name) => !always.includes(name))),
    attributes,
  };
}

function modelOf(control) {
  const chains = control.render(control);
  const depth = chains[0].length;
  for (const chain of chains) {
    if (chain.length !== depth) {
      throw new Error(`${control.label}: renders ${chain.length} deep and ${depth} deep`);
    }
  }
  // An attribute key no render carried is unknown, not absent.
  const vocabulary = new Set(
    chains.flatMap((chain) => chain.flatMap((node) => Object.keys(node.attributes)))
  );
  return {
    chain: chains[0].map((_, depthIndex) =>
      Object.assign(mergeNodes(chains.map((chain) => chain[depthIndex])), { vocabulary })
    ),
    states: new Set(
      chains.map((chain) => chipState(chain[depth - 1], control.chip))
    ),
  };
}

function chipState(chip, chipClass) {
  const disabled = "disabled" in chip.attributes || chip.classes.includes(DISABLED);
  return `${disabled}/${chip.classes.includes(`${chipClass}--selected`)}`;
}

beforeAll(() => {
  const { $ } = loadCompanySearch();
  const ajax = stubAjax($);
  try {
    loadOrderIntent();
    loadScript("views/js/modules/TwoCheckoutManager.js");
    for (const control of CONTROLS) MODEL.set(control.chip, modelOf(control));
  } finally {
    ajax.restore();
    releaseWidgets($);
    document.body.innerHTML = "";
    document.head.innerHTML = "";
  }
});

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

function closingBracket(text, open) {
  let quote = "";
  for (let index = open + 1; index < text.length; index += 1) {
    const character = text[index];
    if (quote) {
      if (character === quote) quote = "";
    } else if (character === '"' || character === "'") quote = character;
    else if (character === "]") return index;
  }
  return text.length;
}

/** @returns {string[]} `text` split on its top-level commas */
function splitArguments(text) {
  const parts = [];
  let start = 0;
  let index = 0;
  while (index < text.length) {
    const character = text[index];
    if (character === "(") index = closingParen(text, index);
    else if (character === "[") index = closingBracket(text, index);
    else if (character === ",") {
      parts.push(text.slice(start, index));
      start = index + 1;
    }
    index += 1;
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

/* A verdict is true, false, or the reason the resolver cannot answer. */
const unanswered = (verdict) => typeof verdict === "string";

function conjunction(verdicts) {
  let reason = null;
  for (const verdict of verdicts) {
    if (verdict === false) return false;
    if (unanswered(verdict)) reason = reason || verdict;
  }
  return reason || true;
}

function disjunction(verdicts) {
  let reason = null;
  for (const verdict of verdicts) {
    if (verdict === true) return true;
    if (unanswered(verdict)) reason = reason || verdict;
  }
  return reason || false;
}

/** @returns {string[]} the simple selectors of one compound, in source order */
function simpleSelectors(compound) {
  const parts = [];
  let index = 0;
  while (index < compound.length) {
    const start = index;
    const head = compound[index];
    if (head === "[") index = closingBracket(compound, index) + 1;
    else if (head === ":") {
      index += compound[index + 1] === ":" ? 2 : 1;
      while (index < compound.length && /[\w-]/.test(compound[index])) index += 1;
      if (compound[index] === "(") index = closingParen(compound, index) + 1;
    } else {
      if (head === "." || head === "#" || head === "*") index += 1;
      while (index < compound.length && /[\w-]/.test(compound[index])) index += 1;
    }
    index = Math.max(index, start + 1);
    parts.push(compound.slice(start, index));
  }
  return parts;
}

/** @returns {{combinator: string, compound: string}[]} compounds, leftmost first */
function complexParts(selector) {
  const parts = [];
  let compound = "";
  let combinator = "";
  let descendant = false;
  let index = 0;
  const flush = () => {
    if (compound !== "") parts.push({ combinator, compound });
    compound = "";
  };
  const text = selector.trim();
  while (index < text.length) {
    const character = text[index];
    let chunk = character;
    if (character === "(") chunk = text.slice(index, closingParen(text, index) + 1);
    else if (character === "[") chunk = text.slice(index, closingBracket(text, index) + 1);
    index += chunk.length;
    if (chunk.length === 1 && /\s/.test(character)) {
      descendant = compound !== "";
    } else if (chunk.length === 1 && COMBINATORS.has(character)) {
      flush();
      combinator = character;
      descendant = false;
    } else {
      if (descendant) {
        flush();
        combinator = " ";
        descendant = false;
      }
      compound += chunk;
    }
  }
  flush();
  return parts;
}

const ATTRIBUTE =
  /^\[\s*([\w-]+)\s*(?:([~^$*|]?=)\s*("[^"]*"|'[^']*'|[^\s\]]+)\s*([isIS])?\s*)?\]$/;

/* Folded on both sides: `type` is one of the attributes HTML matches
   case-insensitively, and ruling such a rule out silently drops it. */
function compareValue(operator, held, quoted) {
  const value = held.toLowerCase();
  const wanted = quoted.replace(/^["']|["']$/g, "").toLowerCase();
  if (operator === "=") return value === wanted;
  if (operator === "~=") return value.split(/\s+/).includes(wanted);
  if (operator === "^=") return value.startsWith(wanted);
  if (operator === "$=") return value.endsWith(wanted);
  if (operator === "*=") return value.includes(wanted);
  return value === wanted || value.startsWith(`${wanted}-`);
}

function matchesAttribute(simple, node) {
  const parsed = ATTRIBUTE.exec(simple);
  if (!parsed) return `attribute selector "${simple}"`;
  const [, name, operator, quoted, flag] = parsed;
  const key = name.toLowerCase();
  if (flag && flag.toLowerCase() === "s") return `case-sensitive flag in "${simple}"`;
  if (!node.vocabulary.has(key)) return `attribute key "${key}"`;
  const held = node.attributes.get(key);
  if (!held) return false;
  if (!held.everywhere) return `presence of "${key}"`;
  if (!operator) return true;
  if (key === "class") {
    return node.varying.size
      ? `value of "class"`
      : compareValue(operator, node.classes.join(" "), quoted);
  }
  if (held.values.size > 1) return `value of "${key}"`;
  return compareValue(operator, Array.from(held.values)[0], quoted);
}

function matchesPseudo(simple, chain, index) {
  const functional = FUNCTIONAL_PSEUDO.exec(simple);
  if (!functional) return `pseudo-class "${simple}"`;
  const any = disjunction(
    splitArguments(functional[2]).map((argument) => matchesComplex(argument, chain, index))
  );
  return functional[1].toLowerCase() === "not" && !unanswered(any) ? !any : any;
}

function matchesClass(node, name) {
  if (node.classes.includes(name)) return true;
  return node.varying.has(name) ? `class "${name}"` : false;
}

function matchesTag(node, tag) {
  if (!node.tags.has(tag)) return false;
  return node.tags.size === 1 ? true : `tag "${tag}"`;
}

function matchesSimple(simple, chain, index) {
  const node = chain[index];
  if (simple === "*") return true;
  if (simple.startsWith(".")) return matchesClass(node, simple.slice(1));
  if (simple.startsWith("#")) return matchesAttribute(`[id="${simple.slice(1)}"]`, node);
  if (simple.startsWith("[")) return matchesAttribute(simple, node);
  if (simple.startsWith(":")) return matchesPseudo(simple, chain, index);
  if (/^[a-z][\w-]*$/i.test(simple)) return matchesTag(node, simple.toLowerCase());
  return `simple selector "${simple}"`;
}

function matchesCompound(compound, chain, index) {
  return conjunction(
    simpleSelectors(compound).map((simple) => matchesSimple(simple, chain, index))
  );
}

/** @returns {boolean|string} whether parts[0..position] reaches chain[index] */
function matchesParts(parts, position, chain, index) {
  const here = matchesCompound(parts[position].compound, chain, index);
  // A compound ruled out settles the selector whatever the rest of it says.
  if (here === false || position === 0) return here;
  const { combinator } = parts[position];
  let left;
  if (combinator === ">") {
    left = index > 0 && matchesParts(parts, position - 1, chain, index - 1);
  } else if (combinator === " ") {
    const ancestors = [];
    for (let up = index - 1; up >= 0; up -= 1) {
      ancestors.push(matchesParts(parts, position - 1, chain, up));
    }
    left = disjunction(ancestors);
  } else {
    left = `combinator "${combinator}"`;
  }
  return conjunction([here, left]);
}

function matchesComplex(selector, chain, index) {
  const parts = complexParts(selector);
  if (parts.length === 0) return `empty selector "${selector}"`;
  return matchesParts(parts, parts.length - 1, chain, index);
}

/** @returns {number[]|null} the winning triple, or null where nothing reaches */
function matchWeight(chain, selectorText) {
  let best = null;
  for (const raw of splitArguments(selectorText)) {
    const selector = rewritePseudoClasses(raw.trim());
    // A ::pseudo-element rule paints a generated box, never the chip's own.
    if (selector === "" || selector.includes("::")) continue;
    const verdict = matchesComplex(selector, chain, chain.length - 1);
    if (unanswered(verdict)) throw new Error(`unmodelled ${verdict} in "${selector}"`);
    if (!verdict) continue;
    const weight = specificity(selector);
    if (!best || compareSpecificity(best, weight) < 0) best = weight;
  }
  return best;
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
function matchingRules(chain, width) {
  const matches = [];
  const keyframes = new Map();
  let order = 0;

  const reject = (rule, description) => {
    if (reachesChip(rule)) {
      throw new Error(`chip rule inside unmodelled ${description}`);
    }
  };

  const reachesChip = (rule) => {
    if (rule.selectorText) return Boolean(matchWeight(chain, rule.selectorText));
    return rule.cssRules ? Array.from(rule.cssRules).some(reachesChip) : false;
  };

  const walk = (rules, live) => {
    for (const rule of Array.from(rules)) {
      if (rule.type === STYLE_RULE) {
        const weight = live ? matchWeight(chain, rule.selectorText) : null;
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

/** @returns {object[]} the chip's ancestors and the chip itself, outermost first */
function chipChain(classes) {
  const chipClass = classes[0];
  const { chain, states } = MODEL.get(chipClass);
  const state = chipState({ classes, attributes: {} }, chipClass);
  if (!states.has(state)) {
    throw new Error(`no render of .${chipClass} is disabled/selected ${state}`);
  }
  const chip = chain[chain.length - 1];
  // The state classes are the case under test; the rest of the chip's are not.
  const owned = new Set([...classes, `${chipClass}--selected`, `${chipClass}--single`]);
  return chain.slice(0, -1).concat({
    ...chip,
    classes: classes.concat(chip.classes.filter((name) => !owned.has(name))),
    varying: new Set(Array.from(chip.varying).filter((name) => !owned.has(name))),
  });
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

/** @returns {string[]} one declaration per entry, in source order */
function declarations(cssText) {
  const parts = [];
  let current = "";
  let depth = 0;
  let quote = "";
  for (const character of cssText) {
    if (quote) {
      if (character === quote) quote = "";
    } else if (character === '"' || character === "'") quote = character;
    else if (character === "(") depth += 1;
    else if (character === ")") depth -= 1;
    else if (character === ";" && depth === 0) {
      parts.push(current);
      current = "";
      continue;
    }
    current += character;
  }
  return parts.concat(current).map((part) => part.trim()).filter(Boolean);
}

const IMPORTANT = /!\s*important\s*$/i;

/** @returns {CSSStyleDeclaration} the chip's declared style */
function chipStyle(classes, width) {
  const winners = matchingRules(chipChain(classes), width || WIDE);
  const declared = winners.flatMap((match) => declarations(match.rule.style.cssText));
  // !important outranks specificity outright, so the important declarations are
  // re-laid after the whole normal tier rather than left in cascade order.
  return styleOf(
    []
      .concat(declared.filter((entry) => !IMPORTANT.test(entry)))
      .concat(declared.filter((entry) => IMPORTANT.test(entry)))
      .join("; ")
  );
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
  const matches = matchingRules(chipChain(classes), width || WIDE);
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
