<?php

declare(strict_types=1);

/**
 * ABN-522 - the custom payment term is deprecated. A stored value can be kept or removed, never
 * changed; a value the merchant record offers as a standard term folds onto that term's checkbox
 * and says so; a value that is not a number of days blocks the section save while it stands.
 */
final class DeprecatedCustomPaymentTermSpec
{
    private const KEY = 'PS_TWO_PAYMENT_TERMS_CUSTOM_DAYS';

    public static function runAll(): void
    {
        self::testStoredTermNormalisation();
        self::testFieldRendersKeepOrRemove();
        self::testSaveStates();
        self::testFoldsInOnlyAgainstAResolvedOfferedSet();
    }

    /**
     * @param int[] $offered
     */
    private static function harness(array $offered): Twopayment
    {
        $module = new class() extends TwopaymentTestHarness {
            /** @return array<int,string> */
            public function validatePaymentTermsForTest(): array
            {
                $this->errors = array();
                $this->validTwoPaymentTermsFormValues();

                return $this->errors;
            }

            public function savePaymentTermsForTest(): string
            {
                $this->output = '';
                $this->saveTwoPaymentTermsFormValues();

                return $this->output;
            }

            /** @return array|null */
            public function legacyCustomTermInputForTest()
            {
                return $this->getTwoLegacyCustomTermInput();
            }
        };
        $module->primeTwoAvailableTerms($offered);

        return $module;
    }

    /** The one normalisation every reader of the stored value goes through. */
    private static function testStoredTermNormalisation(): void
    {
        $cases = array(
            array('', true, null, false, 'an unset value is blank'),
            array('   ', true, null, false, 'whitespace only is blank'),
            array('0', true, null, false, 'a zero is not a term and reads as blank'),
            array('000', true, null, false, 'any run of zeros reads as blank'),
            array(null, true, null, false, 'a missing row is blank'),
            array(array(), true, null, false, 'a non-scalar row is blank'),
            array('30', false, 30, false, 'a plain day count is that term'),
            array('007', false, 7, false, 'leading zeros normalise to the same term'),
            array('  30  ', false, 30, false, 'surrounding whitespace is trimmed'),
            array('30.0', false, null, true, 'a decimal is stored but unusable'),
            array('1e2', false, null, true, 'exponent notation is stored but unusable'),
            array('-5', false, null, true, 'a negative is stored but unusable'),
            array('abc', false, null, true, 'text is stored but unusable'),
        );
        foreach ($cases as list($value, $blank, $days, $unusable, $description)) {
            TinyAssert::same($blank, TwoStoredTerm::isBlank($value), $description . ' - isBlank');
            TinyAssert::same($days, TwoStoredTerm::days($value), $description . ' - days');
            TinyAssert::same($unusable, TwoStoredTerm::isUnusable($value), $description . ' - isUnusable');
        }
    }

    /**
     * The row is absent unless there is something worth showing, and is then keep-or-remove -
     * the only edit offered is the only one the save accepts.
     */
    private static function testFieldRendersKeepOrRemove(): void
    {
        $cases = array(
            array('', array(15, 30), null, 'nothing stored - no row at all'),
            array('0', array(15, 30), null, 'a zero reads as blank - no row'),
            array('30', array(15, 30), null, 'a term offered as standard - no row, the save folds it in'),
            array('45', array(15, 30), '45 days', 'a term no standard checkbox offers - keep or remove'),
            array('abc', array(15, 30), 'abc', 'an unusable value - shown verbatim so it can be corrected'),
            array('<b>x', array(15, 30), '&lt;b&gt;x', 'a value carrying markup - escaped in the option and in the hint'),
            array('30', array(), '30 days', 'an unresolvable offered set folds nothing, so the row stands'),
        );
        foreach ($cases as list($stored, $offered, $keep_label, $description)) {
            StubStore::reset();
            Tools::resetTestValues();
            Configuration::updateValue(self::KEY, $stored);
            $input = self::harness($offered)->legacyCustomTermInputForTest();

            if ($keep_label === null) {
                TinyAssert::same(null, $input, $description);
                continue;
            }

            TinyAssert::same('select', $input['type'], $description . ' - the control is a select');
            TinyAssert::same(self::KEY, $input['name'], $description . ' - the field name is unchanged');
            $query = $input['options']['query'];
            TinyAssert::count(2, $query, $description . ' - exactly keep and remove');
            TinyAssert::same(
                htmlspecialchars($stored, ENT_QUOTES, 'UTF-8'),
                $query[0]['id_option'],
                $description . ' - keep posts the stored value back, escaped for the template'
            );
            TinyAssert::same($keep_label, $query[0]['name'], $description . ' - the keep option names the term');
            TinyAssert::same('', $query[1]['id_option'], $description . ' - remove posts an empty value');
            TinyAssert::same('Remove', $query[1]['name'], $description . ' - remove is offered');
            TinyAssert::true(
                strpos($input['desc'], 'Legacy setting.') === 0,
                $description . ' - the help text says the setting is legacy'
            );
            TinyAssert::true(
                strpos($input['desc'] . $query[0]['name'] . $query[0]['id_option'], '<b>') === false,
                $description . ' - the stored value never reaches the page as markup'
            );
        }
    }

    /** Drives validate-then-save, the order the settings page itself uses. */
    private static function testSaveStates(): void
    {
        // [stored, posted (null = the row was not rendered), refusal, stored after, ticked after, description]
        $cases = array(
            array('', null, '', '', true, 'nothing stored and no row posted saves cleanly'),
            array('45', '45', '', '45', true, 'a term no standard checkbox offers is kept as it is'),
            array('45', '', '', '', true, 'removal is accepted'),
            array(
                '45',
                '60',
                'can only be removed, not changed',
                '45',
                false,
                'replacing the value is refused and nothing in the section saves',
            ),
            array(
                '',
                '30',
                'can only be removed, not changed',
                '',
                false,
                'entering a new value is refused and nothing in the section saves',
            ),
            array(
                '30.0',
                '30.0',
                'which is not a usable number of days',
                '30.0',
                false,
                'keeping an unusable value blocks the whole section save',
            ),
            array('30.0', '', '', '', true, 'an unusable value can still be removed'),
        );
        foreach ($cases as list($stored, $posted, $refusal, $stored_after, $ticked_after, $description)) {
            StubStore::reset();
            Tools::resetTestValues();
            Configuration::updateValue(self::KEY, $stored);
            Tools::setTestValue('PS_TWO_PAYMENT_TERMS_15', 1);
            if ($posted !== null) {
                Tools::setTestValue(self::KEY, $posted);
            }
            $module = self::harness(array(15, 60, 90));
            $errors = $module->validatePaymentTermsForTest();
            if ($errors === array()) {
                $module->savePaymentTermsForTest();
            }

            TinyAssert::same($refusal === '', $errors === array(), $description . ' - whether the save is refused');
            if ($refusal !== '') {
                TinyAssert::true(
                    strpos(implode("\n", $errors), $refusal) !== false,
                    $description . ' - the refusal says what to do'
                );
            }
            TinyAssert::same($stored_after, Configuration::get(self::KEY), $description . ' - the stored term afterwards');
            TinyAssert::same(
                $ticked_after,
                (int) Configuration::get('PS_TWO_PAYMENT_TERMS_15') === 1,
                $description . ' - whether the ticked terms in the same post landed'
            );
        }
    }

    /**
     * The mandatory payment-term selection, and the fold-in itself. An unresolvable offered set
     * matches nothing rather than everything, so an API outage cannot clear a value carried in by
     * an upgrade (ABN-493).
     */
    private static function testFoldsInOnlyAgainstAResolvedOfferedSet(): void
    {
        // [stored, offered, term type, ticked in the post, refusal, stored after, term the fold-in ticked, announced, description]
        $cases = array(
            array('30', array(15, 30), 'STANDARD', array(), '', '', 30, true, 'a term the record offers folds onto its checkbox, announced'),
            array('30', array(15, 30), 'STANDARD', array(30), '', '', 30, true, 'the fold-in stands whether or not the same post ticked that term'),
            array('30', array(), 'STANDARD', array(), '', '30', null, false, 'an unresolvable offered set leaves the stored term standing'),
            array('30', array(15, 60), 'STANDARD', array(15), '', '30', null, false, 'a record that does not offer the term leaves it standing'),
            array(
                '90',
                array(15, 90),
                'EOM',
                array(),
                '',
                '90',
                null,
                false,
                'a term end-of-month checkboxes cannot carry is left standing, not folded away',
            ),
            array(
                '90',
                array(15, 90),
                'STANDARD',
                array(),
                '',
                '',
                90,
                true,
                'the same term folds in under standard terms, where its checkbox does carry it',
            ),
            array(
                '45',
                array(),
                'STANDARD',
                array(),
                '',
                '45',
                null,
                false,
                'a custom term checkout still offers satisfies the mandatory selection alone',
            ),
            array(
                '45',
                array(15, 60),
                'STANDARD',
                array(),
                'at least one payment term',
                '45',
                null,
                false,
                'a custom term checkout would not offer does not satisfy the mandatory selection',
            ),
            array(
                '30.0',
                array(15, 60),
                'STANDARD',
                array(),
                'at least one payment term',
                '30.0',
                null,
                false,
                'an unusable custom term does not satisfy the mandatory selection',
            ),
        );
        foreach ($cases as list($stored, $offered, $type, $ticked, $refusal, $stored_after, $folded, $announced, $description)) {
            StubStore::reset();
            Tools::resetTestValues();
            Configuration::updateValue('PS_TWO_PAYMENT_TERM_TYPE', $type);
            Configuration::updateValue(self::KEY, $stored);
            foreach ($ticked as $term) {
                Tools::setTestValue('PS_TWO_PAYMENT_TERMS_' . $term, 1);
            }
            $module = self::harness($offered);
            $errors = $module->validatePaymentTermsForTest();
            $output = $errors === array() ? $module->savePaymentTermsForTest() : '';

            TinyAssert::same($refusal === '', $errors === array(), $description . ' - whether the save is refused');
            if ($refusal !== '') {
                TinyAssert::true(
                    strpos(implode("\n", $errors), $refusal) !== false,
                    $description . ' - the refusal names what is missing'
                );
            }
            TinyAssert::same($stored_after, Configuration::get(self::KEY), $description . ' - the stored term afterwards');
            if ($folded !== null) {
                TinyAssert::same(
                    1,
                    (int) Configuration::get('PS_TWO_PAYMENT_TERMS_' . $folded),
                    $description . ' - the folded term is ticked'
                );
            }
            TinyAssert::same(
                $announced,
                strpos($output, 'is now one of the standard terms you offer') !== false,
                $description . ' - whether the fold-in is announced'
            );
        }
    }
}
