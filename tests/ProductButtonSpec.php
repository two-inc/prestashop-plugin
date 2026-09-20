<?php

declare(strict_types=1);

/**
 * The product-page buy button (TWO-25800).
 *
 * Adds the item to the basket and lands the buyer on the cart with this method
 * remembered for the payment step. It never places an order, and nothing in it
 * needs an order to exist.
 *
 * Opt-in and independent of the promotional message: the two share a hook and
 * a page predicate, and nothing else.
 */
final class ProductButtonSpec
{
    public static function runAll(): void
    {
        self::testOffByDefault();
        self::testTheTwoFeaturesAreIndependent();
        self::testEveryGateWithholdsOnItsOwn();
        self::testATransientVerdictStillRenders();
        self::testACorruptSwitchReadsAsOff();
        self::testQuickViewIsExcludedButACombinationRefreshIsNot();
        self::testTheAdminFieldIsPresentAndOptional();
        self::testTheButtonNeverPlacesAnOrder();
        self::testTheHandoffDrivesTheShopsOwnForm();
        self::testTheHandlerSurvivesACombinationRefresh();
        self::testTheSwitchSurvivesASave();
        self::testThePreselectWaitsForThePaymentStepWithoutADeadline();
        self::testTheControlAnnouncesTheBrandWithOrWithoutAMark();
        self::testOneAttemptAtATimeThatSurvivesGoingBack();
        self::testTheMarkerIsWrittenOnlyOnAConfirmedAdd();
        self::testTheHandoffIsSkippedAcrossAnOriginBoundary();
        self::testTheBuyerIsToldWhenCoreNeverAnswers();
        self::testEveryWithholdReasonIsSaidOncePerRequest();
        self::testTheMarkerIsConsumedOnceThePaymentStepAnswers();
        self::testTheConsumerLoadsEvenWhenTheButtonIsOff();
        self::testThePreselectIsADefaultNotAnOverride();
    }

    /**
     * A guest passes through address and delivery before the payment step
     * exists at all, and PrestaShop advances each step by POSTing to the order
     * controller, so this usually runs afresh with the options already there.
     * Where a checkout mounts the step in place instead, the wait must outlast
     * a buyer typing an address - which is unknowable, so it is not guessed.
     */
    private static function testThePreselectWaitsForThePaymentStepWithoutADeadline(): void
    {
        $script = self::code(__DIR__ . '/../views/js/modules/TwoCheckoutPreselect.js');

        TinyAssert::true(
            strpos($script, 'MutationObserver') !== false,
            'it watches for the payment step rather than polling for a fixed time'
        );
        TinyAssert::false(
            strpos($script, 'setTimeout') !== false,
            'and carries no deadline that could expire while the buyer is still filling in addresses'
        );

        // What this used to assert - that the marker is cleared only after the
        // option is selected - is now wrong by design. Invariant 4 consumes it
        // whether or not the option turns out to be there, and
        // testTheMarkerIsConsumedOnceThePaymentStepAnswers() covers the rule
        // that replaced it.
    }

    /**
     * The accessible name of the whole control, with and without a brand mark.
     *
     * This is the assertion the change is for. The mark replaces the brand
     * WORD, so the name has to come from its alternative text instead, and a
     * control that has lost the brand from its accessible name is invisible in
     * a screenshot. Computed from the real template rather than asserted
     * against markup, because the failure is what a screen reader concatenates,
     * not which attributes are present.
     */
    private static function testTheControlAnnouncesTheBrandWithOrWithoutAMark(): void
    {
        foreach (
            array(
                'a brand shipping a mark' => 'views/img/TwoLogo.svg',
                'a brand shipping none' => '',
            ) as $why => $logo
        ) {
            TinyAssert::same(
                'Buy with Two',
                self::accessibleName($logo),
                $why . ' still announces the brand'
            );
        }
    }

    /**
     * What a screen reader would announce for the button: its text nodes and
     * its images' alternative text, in document order.
     *
     * The template is Smarty, so the one conditional and the escaped variables
     * are resolved here. Deliberately narrow - it understands exactly the two
     * constructs this template uses, and would fail loudly rather than
     * silently if a third appeared.
     */
    private static function accessibleName(string $logo): string
    {
        $markup = (string) file_get_contents(__DIR__ . '/../views/templates/hook/productbutton.tpl');
        $markup = (string) preg_replace('/\{\*.*?\*\}/s', '', $markup);

        // Resolve {if $two_button_logo} ... {else} ... {/if}
        $markup = (string) preg_replace_callback(
            '/\{if \$two_button_logo\}(.*?)\{else\}(.*?)\{\/if\}/s',
            static function ($m) use ($logo) {
                return $logo === '' ? $m[2] : $m[1];
            },
            $markup
        );

        $values = array(
            'two_button_label_lead' => 'Buy with',
            'two_button_label' => 'Buy with Two',
            'two_button_brand' => 'Two',
            'two_button_logo' => $logo,
            'module_dir' => '/modules/twopayment/',
        );

        $markup = (string) preg_replace_callback(
            '/\{\$([a-z_]+)\|escape:\'html\':\'UTF-8\'\}/',
            static function ($m) use ($values) {
                return htmlspecialchars((string) $values[$m[1]], ENT_QUOTES, 'UTF-8');
            },
            $markup
        );

        TinyAssert::false(strpos($markup, '{') !== false, 'every template construct was resolved');

        $document = new DOMDocument();
        libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8"?><div>' . $markup . '</div>');
        libxml_clear_errors();

        $button = $document->getElementsByTagName('button')->item(0);
        TinyAssert::true($button !== null, 'the control is a button');

        $spoken = '';
        // childNodes() already returns the tree flattened in document order.
        // Wrapping it in a RecursiveArrayIterator would recurse into the DOM
        // objects themselves rather than the document.
        foreach (self::childNodes($button) as $node) {
            if ($node instanceof DOMText) {
                $spoken .= ' ' . $node->textContent;
            } elseif ($node instanceof DOMElement && $node->tagName === 'img') {
                // An empty alt contributes nothing, which is exactly what a
                // decorative mark would do - and the bug this guards.
                $spoken .= ' ' . $node->getAttribute('alt');
            }
        }

        return trim((string) preg_replace('/\s+/', ' ', $spoken));
    }

    /** @return array<int, DOMNode> every descendant, in document order. */
    private static function childNodes(DOMNode $node): array
    {
        $found = array();

        foreach ($node->childNodes as $child) {
            $found[] = $child;
            if ($child->hasChildNodes()) {
                $found = array_merge($found, self::childNodes($child));
            }
        }

        return $found;
    }

    /**
     * Invariant 1. One attempt at a time: the button latches on click so a
     * double click cannot open a second one, and unlatches on back-forward
     * restore, which hands back the page with the latch still set against an
     * attempt that finished long ago.
     */
    private static function testOneAttemptAtATimeThatSurvivesGoingBack(): void
    {
        $script = self::code(__DIR__ . '/../views/js/product-button.js');

        TinyAssert::true(
            strpos($script, "setAttribute('disabled', 'disabled')") !== false,
            'the attempt latches'
        );
        TinyAssert::true(
            strpos($script, "addEventListener('pageshow'") !== false,
            'and a page restored from the back-forward cache releases it'
        );
        TinyAssert::true(
            strpos($script, 'event.persisted') !== false,
            'restores only, so an ordinary load never releases a control the theme disabled on purpose'
        );
        // The latch must NOT live on the element. Choosing a combination
        // mid-flight replaces this button with a fresh, undisabled node, so a
        // latch read off the node is gone exactly when it matters and the
        // buyer can be charged for two units of one purchase.
        TinyAssert::true(
            strpos($script, 'var inFlight = false;') !== false,
            'the latch is held outside the DOM'
        );
        TinyAssert::true(
            strpos($script, 'if (inFlight) {') !== false,
            'and a click during an attempt in flight is refused by it'
        );
        TinyAssert::false(
            self::appearsBefore($script, "hasAttribute('disabled')", 'handle(button)'),
            'never by reading the disabled attribute off the node, which a re-render discards'
        );
    }

    /**
     * Invariants 2 and 3. The marker records that something IS in the basket,
     * so it may only be written once this attempt's own response says so. A
     * refusal core can explain answers with a populated errors array and no
     * success key at all.
     */
    private static function testTheMarkerIsWrittenOnlyOnAConfirmedAdd(): void
    {
        $script = self::code(__DIR__ . '/../views/js/product-button.js');

        TinyAssert::true(
            strpos($script, 'payload.success !== true') !== false,
            'success is what the response says, not the absence of an exception'
        );

        // The failure this replaces: writing the marker before submitting, so
        // a refused add still preselected the buyer's next checkout.
        TinyAssert::true(
            self::appearsBefore($script, 'succeeded(payload)', 'sessionStorage.setItem'),
            'and the marker is written only after that answer'
        );
    }

    /**
     * Invariant 4, the other half. What consumes the marker cannot be
     * conditional on the thing that wrote it: the merchant can switch the
     * button off, or the key can be rejected, between the add and the
     * checkout, and that buyer's tab would then hold a marker forever with
     * nothing left to consume it.
     */
    private static function testTheConsumerLoadsEvenWhenTheButtonIsOff(): void
    {
        $source = file_get_contents(__DIR__ . '/../twopayment.php');
        $offset = strpos($source, "'two-checkout-preselect'");

        TinyAssert::true($offset !== false, 'the consumer is registered on checkout');

        // The registration must not sit inside the button's own gate.
        $preceding = substr($source, max(0, $offset - 900), 900);
        TinyAssert::false(
            strpos($preceding, 'isTwoProductButtonWarranted()') !== false,
            'and its registration is not gated on the button being switched on'
        );
    }

    /**
     * Invariant 4. Consumed exactly once and always, at the moment the payment
     * step can answer whether the method is on offer. A marker surviving an
     * unavailable checkout would preselect an unrelated later one.
     */
    private static function testTheMarkerIsConsumedOnceThePaymentStepAnswers(): void
    {
        $script = self::code(__DIR__ . '/../views/js/modules/TwoCheckoutPreselect.js');

        TinyAssert::true(
            strpos($script, 'if (!mounted()) {') !== false,
            'a render with no payment step is not an answer and keeps the marker'
        );
        TinyAssert::true(
            self::appearsBefore($script, 'clear();', 'if (!input || input.checked)'),
            'and once the step exists the marker is cleared whether or not the option is there'
        );
    }

    /**
     * Invariant 6. A default, never an override. A radio the theme happens to
     * default to is not a choice the buyer made, which is why this turns on
     * isTrusted rather than on anything merely being checked.
     */
    private static function testThePreselectIsADefaultNotAnOverride(): void
    {
        $script = self::code(__DIR__ . '/../views/js/modules/TwoCheckoutPreselect.js');

        TinyAssert::true(
            strpos($script, 'event.isTrusted') !== false,
            'a choice the buyer makes in this checkout ends the preselection'
        );
        TinyAssert::true(
            strpos($script, 'input.checked') !== false,
            'and an option already selected is never clicked again'
        );
    }

    /**
     * sessionStorage is keyed by the FULL origin. A shop with SSL enabled but
     * not everywhere serves the product page over http while the cart and the
     * checkout declare $ssl = true and are served over https, so a marker
     * written on the product page could never be read at the checkout and the
     * preselection would fail silently on every such shop.
     *
     * Nothing is recorded there at all: the button adds to the basket and the
     * buyer picks their own method, which is a whole feature working rather
     * than half of one. The module says so once per request, so it is not
     * silent.
     */
    private static function testTheHandoffIsSkippedAcrossAnOriginBoundary(): void
    {
        $script = self::code(__DIR__ . '/../views/js/product-button.js');
        $markup = self::code(__DIR__ . '/../views/templates/hook/productbutton.tpl');
        $source = file_get_contents(__DIR__ . '/../twopayment.php');

        TinyAssert::true(
            strpos($markup, 'data-two-checkout-url') !== false,
            'the button carries where the checkout actually lives'
        );
        TinyAssert::true(
            // The CALL site, not the declaration: 'handoffReaches(button)'
            // alone also matches `function handoffReaches(button)`, which
            // precedes setItem whether or not it is ever called.
            self::appearsBefore($script, 'if (handoffReaches(button)) {', 'sessionStorage.setItem'),
            'and nothing is recorded unless the checkout could read it'
        );
        TinyAssert::true(
            strpos($script, '.origin === window.location.origin') !== false,
            'compared by origin, which is what sessionStorage is keyed by'
        );

        // Core's own rule for a controller declaring $ssl = true is
        // ($this->ssl && PS_SSL_ENABLED), independent of _EVERYWHERE, so the
        // URL handed to the page has to be built the same way or it would
        // disagree with where the buyer actually lands.
        TinyAssert::true(
            self::appearsBefore($source, "getPageLink(\n                'order',", "PS_SSL_ENABLED')\n            ),")
            || strpos($source, "(bool) Configuration::get('PS_SSL_ENABLED')") !== false,
            'the checkout URL is resolved by the same rule core uses'
        );
        TinyAssert::true(
            strpos($source, 'PS_SSL_ENABLED_EVERYWHERE') !== false,
            'and the mismatch is reported rather than left silent'
        );
    }

    /**
     * Every reason this feature withholds something is a static fact about the
     * shop's configuration - a corrupt switch, a brand with no product name, a
     * checkout on another origin - so each is said once per request. A
     * catalogue's worth of product views would otherwise write the same line
     * until the log is no use to the person it was written for.
     */
    private static function testEveryWithholdReasonIsSaidOncePerRequest(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../twopayment.php');

        // The feature's own render path, not the whole module.
        $from = strpos($source, 'private function renderTwoProductButton()');
        $to = strpos($source, 'private static function logTwoProductButtonToggleOnce');
        TinyAssert::true($from !== false && $to !== false && $to > $from, 'the render path is locatable');

        $path = substr($source, $from, $to - $from);

        // Per SITE, not a count of each: a total of guards against a total of
        // logs stays satisfied when a guard is removed from one site and
        // another has two, which is how the first version of this passed while
        // an unguarded log sat right in front of it.
        $checked = 0;
        $offset = 0;

        while (($at = strpos($path, 'PrestaShopLogger::addLog', $offset)) !== false) {
            $offset = $at + 1;
            ++$checked;

            $preceding = substr($path, max(0, $at - 400), min(400, $at));

            TinyAssert::true(
                strpos($preceding, 'static $') !== false,
                'the log site at offset ' . $at . ' of the button render path is behind a once-per-request guard'
            );
        }

        TinyAssert::true($checked > 0, 'there are log sites to check');
    }

    /**
     * A request that rejects, or one that never answers at all, must reach the
     * buyer as words.
     *
     * Both used to end the same way: the latch quietly released and the button
     * re-enabled itself, which to the buyer is indistinguishable from a click
     * that did nothing. Core supplies wording for a refusal it can explain;
     * there is none for a request that never arrived, so the module ships its
     * own. Unbounded, the second case never even got that far - the latch was
     * held for as long as the page stayed open.
     */
    private static function testTheBuyerIsToldWhenCoreNeverAnswers(): void
    {
        $script = self::code(__DIR__ . '/../views/js/product-button.js');
        $markup = self::code(__DIR__ . '/../views/templates/hook/productbutton.tpl');

        TinyAssert::true(
            strpos($markup, 'data-two-unreachable') !== false,
            'the button carries wording for a request that never answered'
        );
        TinyAssert::true(
            strpos($script, "report(button, button.getAttribute('data-two-unreachable')") !== false,
            'and a rejected attempt shows it rather than failing silently'
        );

        // The bound, and an abort rather than merely giving up waiting, so a
        // hung request does not keep a connection the buyer abandoned.
        TinyAssert::true(
            strpos($script, 'TIMEOUT_MS') !== false,
            'the attempt is bounded'
        );
        TinyAssert::true(
            strpos($script, 'controller.abort()') !== false,
            'and the request is aborted, not just abandoned'
        );
        TinyAssert::true(
            self::appearsBefore($script, 'request.signal = controller.signal;', 'window.fetch(form.action, request)'),
            'with the signal attached to the request it bounds'
        );
    }

    /**
     * Whether $first appears before $second, refusing to answer unless BOTH
     * are present.
     *
     * strpos() returns false for a needle that is not there, and false < 5 is
     * true in PHP, so comparing two raw offsets quietly passes when the thing
     * being ordered has been deleted outright - which is the failure these
     * assertions exist to catch.
     */
    private static function appearsBefore(string $haystack, string $first, string $second): bool
    {
        $a = strpos($haystack, $first);
        $b = strpos($haystack, $second);

        if ($a === false || $b === false) {
            return false;
        }

        return $a < $b;
    }

    /**
     * A file's code with its comments removed.
     *
     * These assertions are about what the script DOES. The comments
     * deliberately name the wrong approach they replaced, so matching against
     * the raw file would fail on its own explanation of the bug.
     */
    private static function code(string $path): string
    {
        $source = (string) file_get_contents($path);
        $source = preg_replace('#/\*.*?\*/#s', '', $source);

        return (string) preg_replace('#^\s*//.*$#m', '', $source);
    }

    private static function reset(): void
    {
        StubStore::reset();
        Tools::resetTestValues();
    }

    private static function enabled(): TwopaymentTestHarness
    {
        $module = new TwopaymentTestHarness();
        Configuration::updateValue('PS_TWO_PRODUCT_BUTTON_ENABLED', 1);

        return $module;
    }

    /** An upgrade puts no purchase control on anyone's storefront by itself. */
    private static function testOffByDefault(): void
    {
        self::reset();
        $module = new TwopaymentTestHarness();

        TinyAssert::false(
            $module->isTwoProductButtonWarranted(),
            'nothing stored means no button'
        );
    }

    /**
     * Separate switches, so a shop may run either alone. A shared one would
     * force a merchant who wanted the button to advertise as well.
     */
    private static function testTheTwoFeaturesAreIndependent(): void
    {
        self::reset();
        $module = new TwopaymentTestHarness();
        Configuration::updateValue('PS_TWO_PRODUCT_BUTTON_ENABLED', 1);

        TinyAssert::true($module->isTwoProductButtonWarranted(), 'the button is on');
        TinyAssert::false($module->isTwoProductMessageWarranted(), 'and the message stayed off');

        self::reset();
        $module = new TwopaymentTestHarness();
        Configuration::updateValue('PS_TWO_PRODUCT_MESSAGE_ENABLED', 1);

        TinyAssert::true($module->isTwoProductMessageWarranted(), 'the message is on');
        TinyAssert::false($module->isTwoProductButtonWarranted(), 'and the button stayed off');
    }

    /**
     * A button a buyer cannot use is worse than no button: it asks them to
     * commit to a method that will not be there.
     */
    private static function testEveryGateWithholdsOnItsOwn(): void
    {
        foreach (
            array(
                array(Twopayment::API_KEY_STATUS_INVALID, 'a key Two rejected'),
                array(Twopayment::API_KEY_STATUS_NOT_CONFIGURED, 'no key at all'),
            ) as $case
        ) {
            list($status, $why) = $case;

            self::reset();
            $module = self::enabled();
            $module->primeTwoApiKeyStatus($status, 401);

            TinyAssert::false(
                $module->isTwoProductButtonWarranted(),
                $why . ' withholds the button'
            );
        }
    }

    /** An outage is not a rejection, and must not empty the product page. */
    private static function testATransientVerdictStillRenders(): void
    {
        self::reset();
        $module = self::enabled();
        $module->primeTwoApiKeyStatus(Twopayment::API_KEY_STATUS_VERIFYING, 503);

        TinyAssert::true(
            $module->isTwoProductButtonWarranted(),
            'a transient verdict leaves the button up, exactly as it leaves the method on offer'
        );
    }

    /** Strict: only the stored '1' enables it, so a corrupt row is not coerced on. */
    private static function testACorruptSwitchReadsAsOff(): void
    {
        foreach (array('yes', '2', 'true', ' 1') as $stored) {
            self::reset();
            // Written from a cold key, never over a stored 1: core's
            // updateValue short-circuits a numeric write that compares equal
            // to what is already there, so seeding 1 first would leave ' 1'
            // unwritten and the case would prove nothing.
            $module = new TwopaymentTestHarness();
            Configuration::updateValue('PS_TWO_PRODUCT_BUTTON_ENABLED', $stored);

            TinyAssert::false(
                $module->isTwoProductButtonWarranted(),
                var_export($stored, true) . ' is not a value the switch understands'
            );
        }
    }

    /**
     * Quick View renders this hook from a fragment request whose markup lands
     * on a category page that never registered the button's stylesheet or its
     * script, so the button would be both unstyled and inert. A combination
     * refresh is the same controller over ajax and DOES render.
     */
    private static function testQuickViewIsExcludedButACombinationRefreshIsNot(): void
    {
        foreach (
            array(
                array(array('action' => 'quickview'), false, 'the theme\'s Quick View request'),
                array(array('quickview' => '1'), false, 'the direct-URL Quick View form'),
                array(array('action' => 'refresh'), true, 'a combination refresh'),
                array(array(), true, 'an ordinary product page view'),
            ) as $case
        ) {
            list($request, $expected, $why) = $case;

            self::reset();
            $module = self::enabled();
            Tools::setTestValue('controller', 'product');
            foreach ($request as $key => $value) {
                Tools::setTestValue($key, $value);
            }

            // The stub smarty renders nothing, so the assignment is the
            // evidence that the hook got as far as drawing the button.
            $module->context->smarty->assigned = array();
            $module->hookDisplayProductAdditionalInfo(array());
            $rendered = isset($module->context->smarty->assigned['two_button_label']);

            TinyAssert::same($expected, $rendered, $why . ' renders the button: ' . var_export($expected, true));
        }
    }

    private static function testTheAdminFieldIsPresentAndOptional(): void
    {
        self::reset();
        StubStore::$languages = array(array('id_lang' => 1));
        $module = new TwopaymentTestHarness();
        $form = (new ReflectionMethod(Twopayment::class, 'getTwoCheckoutFieldsForm'))->invoke($module);

        $byName = array();
        foreach ($form['form']['input'] as $input) {
            if (isset($input['name'])) {
                $byName[$input['name']] = $input;
            }
        }

        TinyAssert::true(isset($byName['PS_TWO_PRODUCT_BUTTON_ENABLED']), 'the switch is on the form');
        TinyAssert::same('switch', $byName['PS_TWO_PRODUCT_BUTTON_ENABLED']['type']);
        TinyAssert::false(
            !empty($byName['PS_TWO_PRODUCT_BUTTON_ENABLED']['required']),
            'and it is optional, like every other opt-in'
        );
    }

    /**
     * The whole design rests on this. Everything an order needs — the company
     * identity, the addresses, the final totals — is still collected at
     * checkout, so nothing on the product page may reach order creation.
     */
    private static function testTheButtonNeverPlacesAnOrder(): void
    {
        $markup = self::code(__DIR__ . '/../views/templates/hook/productbutton.tpl');
        $script = self::code(__DIR__ . '/../views/js/product-button.js');

        foreach (array('/v1/order', 'orderintent', 'order-intent') as $forbidden) {
            TinyAssert::false(
                stripos($markup . $script, $forbidden) !== false,
                'the product-page button does not reach ' . $forbidden
            );
        }

        TinyAssert::true(
            strpos($script, "params.set('add', '1')") !== false,
            'it hands off to core add-to-cart and nothing else'
        );
    }

    /**
     * The form carries the buyer's actual choice; a hand-built request does
     * not. The standard product form has NO id_product_attribute field, only
     * group[...] controls, so anything naming the fields it wants sends the
     * default combination however carefully it is written.
     */
    private static function testTheHandoffDrivesTheShopsOwnForm(): void
    {
        $markup = file_get_contents(__DIR__ . '/../views/templates/hook/productbutton.tpl');
        $script = self::code(__DIR__ . '/../views/js/product-button.js');

        TinyAssert::true(
            strpos($script, 'new FormData(form)') !== false,
            'the shop\'s own form is serialised whole rather than rebuilt'
        );

        // The failure this replaces: a reconstructed request naming its own
        // fields, which cannot see a combination the form expresses as
        // group[...] and silently adds the default one instead.
        foreach (array('id_product_attribute', 'group[', 'qty=', 'id_product=') as $reconstructed) {
            TinyAssert::false(
                strpos($script, $reconstructed) !== false,
                'it does not rebuild the request field by field (' . $reconstructed . ')'
            );
        }

        // A destination of its own could only ever disagree with the form's.
        TinyAssert::false(
            strpos($markup, 'cart-url') !== false,
            'and it carries no cart URL of its own'
        );
    }

    /**
     * Choosing a combination re-renders product_additional_info, the fragment
     * this button lives in. A listener bound to the node is thrown away with
     * it and the replacement would be inert, so the button would work exactly
     * once and only before the buyer picked anything.
     */
    private static function testTheHandlerSurvivesACombinationRefresh(): void
    {
        $script = self::code(__DIR__ . '/../views/js/product-button.js');

        TinyAssert::true(
            strpos($script, "document.addEventListener('click'") !== false,
            'the click handler is delegated to the document'
        );
        TinyAssert::false(
            strpos($script, 'DOMContentLoaded') !== false,
            'and nothing is bound once at load, when the node is still the first one'
        );
    }

    /**
     * The whole feature is unreachable if the save path does not write the
     * switch: the merchant flips it, saves, and the shop still reads the
     * install default forever. Driven through the real save, because a spec
     * that writes Configuration directly cannot see this at all.
     */
    private static function testTheSwitchSurvivesASave(): void
    {
        foreach (
            array(
                'PS_TWO_PRODUCT_BUTTON_ENABLED',
                // The message switch rides along: same save, same failure
                // mode, and nothing else covers it.
                'PS_TWO_PRODUCT_MESSAGE_ENABLED',
            ) as $key
        ) {
            foreach (array('1' => 1, '0' => 0) as $posted => $expected) {
                self::reset();
                StubStore::$languages = array(array('id_lang' => 1));
                $module = new TwopaymentTestHarness();

                Tools::setTestValue($key, $posted);
                (new ReflectionMethod(Twopayment::class, 'saveTwoCheckoutFieldsFormValues'))->invoke($module);

                TinyAssert::same(
                    $expected,
                    (int) Configuration::get($key),
                    $key . ' posted as ' . $posted . ' is what the shop stores'
                );
            }
        }
    }
}
