/**
 * ABN-554. What a payment-term chip says the term is. An end-of-month term
 * falls due that many days after the end of the month, so a chip carrying only
 * a day count states the wrong due date for it.
 *
 * jsdom has no accessibility layer, so the accessible name is asserted as the
 * `aria-label` attribute; what a screen reader utters is a browser check.
 */

"use strict";

const {
  loadCompanySearch,
  loadOrderIntent,
  loadScript,
  releaseWidgets,
  stubAjax,
  flushPromises,
} = require("./ps-harness");

const CHECKOUT_HOST = "https://api.example.test";
const ORDER_INTENT_URL =
  "https://shop.example.test/module/twopayment/orderintent";

let TwoCheckoutManager;
let $;
let ajax;

function makeChips(terms, termType, options) {
  const manager = new TwoCheckoutManager({
    checkoutHost: CHECKOUT_HOST,
    orderIntentEnabled: false,
    orderIntentUrl: ORDER_INTENT_URL,
    ajaxToken: "test-token",
    available_payment_terms: terms,
    default_payment_term: terms[0],
    payment_term_type: termType,
  });
  document.body.innerHTML = [
    '<div class="two-term-chips">',
    (options && options.withoutTitle
      ? ""
      : '  <h4 class="two-terms-title" id="two-terms-title">Choose a term</h4>'),
    '  <div class="two-term-chips__container" id="two-terms-chips"></div>',
    '  <div class="two-terms-selected">',
    '    <span class="two-terms-selected-days" id="two-selected-days"></span>',
    "  </div>",
    "</div>",
  ].join("\n");
  manager.initializePaymentTerms();
  return manager;
}

/** @returns {string} the summary sentence below the chip strip */
function summaryText() {
  return document.querySelector("#two-selected-days").textContent;
}

/** @returns {HTMLElement} the chip strip's radiogroup */
function group() {
  return document.querySelector("#two-terms-chips");
}

function chips() {
  return Array.prototype.slice.call(
    document.querySelectorAll(".two-term-chip"),
  );
}

function chipTexts() {
  return chips().map(
    (chip) => chip.querySelector(".two-term-chip__days").textContent,
  );
}

function chipNames() {
  return chips().map((chip) => [
    chip.getAttribute("aria-label"),
    chip.getAttribute("title"),
  ]);
}

beforeEach(() => {
  const loaded = loadCompanySearch();
  $ = loaded.$;
  ajax = stubAjax($);
  loadOrderIntent();
  loadScript("views/js/modules/TwoCheckoutManager.js");
  TwoCheckoutManager = window.TwoCheckoutManager;
});

afterEach(async () => {
  ajax.calls.forEach((call) => {
    if (!call.aborted) {
      try {
        call.fail("abort", "abort");
      } catch (e) {
        // some call sites wire .done()/.fail() directly
      }
    }
  });
  await flushPromises();
  ajax.restore();
  releaseWidgets($);
  document.body.innerHTML = "";
});

describe("what a payment-term chip says", () => {
  const CASES = [
    {
      termType: "STANDARD",
      terms: [30, 60],
      texts: ["30 days", "60 days"],
      names: [
        [null, null],
        [null, null],
      ],
      case: "a standard term states the days, and needs no name of its own",
    },
    {
      termType: "EOM",
      terms: [30, 60],
      texts: ["EOM+30", "EOM+60"],
      names: [
        [
          "EOM+30: pay 30 days after the end of the month",
          "EOM+30: pay 30 days after the end of the month",
        ],
        [
          "EOM+60: pay 60 days after the end of the month",
          "EOM+60: pay 60 days after the end of the month",
        ],
      ],
      case: "an end-of-month term names the month end and spells it out",
    },
    {
      termType: "STANDARD",
      terms: [30],
      texts: ["30 days"],
      names: [[null, null]],
      case: "a single standard term reads the same way",
    },
    {
      termType: "EOM",
      terms: [30],
      texts: ["EOM+30"],
      names: [
        [
          "EOM+30: pay 30 days after the end of the month",
          "EOM+30: pay 30 days after the end of the month",
        ],
      ],
      case: "so does a single end-of-month term",
    },
    {
      termType: "",
      terms: [30],
      texts: ["30 days"],
      names: [[null, null]],
      case: "an unset term type reads as standard",
    },
  ];

  test.each(CASES)("$case", ({ terms, termType, texts, names }) => {
    makeChips(terms, termType);

    expect(chipTexts()).toEqual(texts);
    expect(chipNames()).toEqual(names);
  });

  test("the accessible name contains the visible text", () => {
    makeChips([1, 30, 120], "EOM");

    // WCAG 2.5.3 Label in Name.
    chipTexts().forEach((text, i) => {
      expect(chipNames()[i][0]).toContain(text);
    });
  });
});

/**
 * An aria-label replaces the whole accessible name, so the "+7.25 EUR" rendered
 * inside an end-of-month chip is announced nowhere unless the name states it
 * too. The quote lands after the chip does, so the name is restated then.
 */
describe("the surcharge in the chip name", () => {
  const ORDER_INTENT = {
    order_intent_url: ORDER_INTENT_URL,
    ajax_token: "test-token",
    checkout_host: CHECKOUT_HOST,
  };

  beforeEach(() => {
    window.twopayment = Object.assign({}, ORDER_INTENT);
  });

  afterEach(() => {
    delete window.twopayment;
  });

  test.each([
    {
      termType: "EOM",
      amounts: { 30: 7.25, 60: 9 },
      expected: [
        "EOM+30: pay 30 days after the end of the month, plus a 7.25 EUR surcharge",
        "EOM+60: pay 60 days after the end of the month, plus a 9.00 EUR surcharge",
      ],
      case: "a priced term states the fee the label would otherwise silence",
    },
    {
      termType: "EOM",
      amounts: { 30: 0, 60: 0 },
      expected: [
        "EOM+30: pay 30 days after the end of the month",
        "EOM+60: pay 60 days after the end of the month",
      ],
      case: "a set quoting nothing states no amount",
    },
    {
      termType: "STANDARD",
      amounts: { 30: 7.25, 60: 9 },
      expected: [null, null],
      case: "a standard term is left unnamed whatever it costs",
    },
  ])("$case", ({ termType, amounts, expected }) => {
    makeChips([30, 60], termType);
    ajax.last().succeed({ success: true, currency: "eur", amounts: amounts });

    expect(chipNames().map((pair) => pair[0])).toEqual(expected);
    expect(chipNames().map((pair) => pair[1])).toEqual(expected);
  });

  test("the name picks the fee up when the quote lands", () => {
    makeChips([30, 60], "EOM");

    expect(chipNames()[0][0]).toBe(
      "EOM+30: pay 30 days after the end of the month",
    );

    ajax.last().succeed({ success: true, currency: "eur", amounts: { 30: 7.25, 60: 9 } });

    expect(chipNames()[0][0]).toBe(
      "EOM+30: pay 30 days after the end of the month, plus a 7.25 EUR surcharge",
    );
  });

  test("a failed quote leaves a name claiming no amount", () => {
    makeChips([30, 60], "EOM");
    ajax.last().fail("error");

    expect(chipNames()[0][0]).toBe(
      "EOM+30: pay 30 days after the end of the month",
    );
  });

  test("the accessible name contains the visible text and the visible amount", () => {
    makeChips([1, 30, 120], "EOM");
    ajax
      .last()
      .succeed({ success: true, currency: "eur", amounts: { 1: 1, 30: 7.25, 120: 30 } });

    // WCAG 2.5.3 Label in Name.
    chipTexts().forEach((text, i) => {
      expect(chipNames()[i][0]).toContain(text);
    });
    expect(chipNames()[1][0]).toContain("7.25 EUR");
  });
});

/**
 * ABN-554. PrestaShop is the only checkout with a "Pay in N days" summary below
 * the chip strip. It is ONE translated sentence with the day count substituted:
 * a sentence assembled from 'Pay in' + N + 'days' + 'from end of month' cannot
 * be reordered or word-agreed by a translator.
 */
describe("the summary sentence below the chips", () => {
  afterEach(() => {
    delete window.twopayment;
  });

  test.each([
    {
      termType: "STANDARD",
      terms: [30, 60],
      expected: "Pay in 30 days",
      case: "a standard term",
    },
    {
      termType: "STANDARD",
      terms: [1],
      expected: "Pay in 1 days",
      case: "the shortest standard term",
    },
    {
      termType: "EOM",
      terms: [30, 60],
      expected: "Pay in 30 days from end of month",
      case: "an end-of-month term",
    },
    {
      termType: "EOM",
      terms: [120],
      expected: "Pay in 120 days from end of month",
      case: "a three-digit end-of-month term",
    },
  ])("states the term in one sentence: $case", ({ terms, termType, expected }) => {
    makeChips(terms, termType);

    expect(summaryText()).toBe(expected);
  });

  test.each([
    {
      termType: "STANDARD",
      i18n: { pay_in_days: "Betaal in %s dagen" },
      expected: "Betaal in 30 dagen",
      case: "a standard term",
    },
    {
      termType: "EOM",
      i18n: { pay_in_days_eom: "Betaal in %s dagen vanaf einde van de maand" },
      expected: "Betaal in 30 dagen vanaf einde van de maand",
      case: "an end-of-month term",
    },
  ])(
    "takes the whole sentence from one catalogue key: $case",
    ({ termType, i18n, expected }) => {
      // A fragment left hardcoded would survive this: only the substituted day
      // count may come from outside the translated sentence.
      window.twopayment = { i18n: i18n };
      makeChips([30, 60], termType);

      expect(summaryText()).toBe(expected);
    }
  );
});

/**
 * ABN-554. A chip's visible text states only the term, so nothing inside the
 * group names it — the strip's title does, one term or several.
 */
describe("the chip group's accessible name", () => {
  afterEach(() => {
    delete window.twopayment;
  });

  test.each([
    { terms: [30, 60], case: "several offered terms" },
    { terms: [30], case: "one offered term" },
  ])("is the strip title, with $case", ({ terms }) => {
    makeChips(terms, "STANDARD");

    expect(group().getAttribute("role")).toBe("radiogroup");
    expect(group().getAttribute("aria-labelledby")).toBe("two-terms-title");
    expect(document.getElementById("two-terms-title")).not.toBeNull();
  });

  // The tests above build their own DOM, so none of them sees the shipped
  // markup stop emitting the id the group points at.
  test.each([
    { file: "views/templates/hook/paymentinfo.tpl", case: "the payment-info template" },
    { file: "views/js/modules/TwoCheckoutManager.js", case: "the injected fallback strip" },
  ])("$case emits the id the group points at", ({ file }) => {
    const source = require("fs").readFileSync(
      require("path").join(__dirname, "..", "..", file),
      "utf8",
    );

    expect(source).toMatch(
      /<h4 class="two-terms-title" id="two-terms-title">/,
    );
  });

  test("falls back to the same wording when a theme supplies no title", () => {
    window.twopayment = { i18n: { choose_payment_terms: "Kies een termijn" } };
    makeChips([30], "STANDARD", { withoutTitle: true });

    expect(group().getAttribute("aria-labelledby")).toBeNull();
    expect(group().getAttribute("aria-label")).toBe("Kies een termijn");
  });
});
