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

function makeChips(terms, termType) {
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
    '  <div class="two-term-chips__container" id="two-terms-chips"></div>',
    '  <div class="two-terms-selected">',
    '    <span class="two-terms-selected-days" id="two-selected-days"></span>',
    "  </div>",
    "</div>",
  ].join("\n");
  manager.initializePaymentTerms();
  return manager;
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
