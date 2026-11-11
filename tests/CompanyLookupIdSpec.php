<?php

declare(strict_types=1);

require_once __DIR__ . '/../controllers/front/orderintent.php';

final class StubCompanyLookupResponded extends RuntimeException
{
}

/**
 * TWO-27248: the company lookup id goes into the request path, so only an id
 * that starts with a letter or digit and stays in the registry id charset is
 * sent on to Two. Anything else is refused before any request is made.
 */
final class CompanyLookupIdSpec
{
    private const CART_ID = 27248;

    public static function runAll(): void
    {
        self::testOnlyARegistryIdIsLookedUp();
    }

    private static function testOnlyARegistryIdIsLookedUp(): void
    {
        // [lookup_id as posted, looked up, description].
        $cases = [
            ['.', false, 'a lone dot is refused'],
            ['..', false, 'two dots are refused'],
            ['../x', false, 'a parent path is refused'],
            ['.hidden', false, 'a leading dot is refused'],
            ['a/..', false, 'a slash is refused'],
            ['-x', false, 'a leading hyphen is refused'],
            ['', false, 'an empty id is refused'],
            ['GB 123', false, 'inner whitespace is refused'],
            ['GB:12345678', true, 'a prefixed registry id is looked up'],
            [' 987654321 ', true, 'a trimmed numeric id is looked up'],
            ['a..b', true, 'dots inside the id are looked up'],
        ];
        foreach ($cases as [$lookupId, $lookedUp, $description]) {
            [$requests, $emitted] = self::lookUp($lookupId);

            $expected = $lookedUp ? ['/companies/v2/company/' . rawurlencode(trim($lookupId))] : [];
            TinyAssert::same($expected, $requests, 'requests made: ' . $description);
            TinyAssert::same(
                [$lookedUp ? ['name' => 'Lookup Trading Ltd'] : ['error_code' => 'INVALID_REQUEST']],
                $emitted,
                'body sent to the browser: ' . $description
            );
        }
    }

    /**
     * Drive the company details action with this lookup id.
     *
     * @return array{0:array<int,string>,1:array<int,mixed>} the endpoints requested, and the bodies sent to the browser
     */
    private static function lookUp(string $lookupId): array
    {
        StubStore::reset();
        PrestaShopLogger::reset();
        Tools::resetTestValues();
        Tools::setTestValue('token', Tools::getToken(false));
        Tools::setTestValue('lookup_id', $lookupId);
        Context::getContext()->cart = new Cart(self::CART_ID);
        Context::getContext()->cookie = new Cookie();

        $controller = new class extends TwopaymentOrderintentModuleFrontController {
            /** @var array<int,mixed> */
            public array $emitted = [];

            public function sendJsonResponse($content)
            {
                $this->emitted[] = json_decode((string) $content, true);

                throw new StubCompanyLookupResponded('responded');
            }
        };
        $controller->module = new class extends TwopaymentTestHarness {
            /** @var array<int,string> */
            public $requests = [];

            public function setTwoPaymentRequest($endpoint, $payload = [], $method = 'POST', $additional_headers = [], $timeout = null)
            {
                $this->requests[] = $endpoint;

                return ['http_status' => 200, 'data' => ['name' => 'Lookup Trading Ltd']];
            }
        };
        try {
            // @: the relay sets the HTTP status, which a CLI run that has printed cannot.
            @$controller->ajaxProcessCompanyDetails();
        } catch (StubCompanyLookupResponded $e) {
        }

        return [$controller->module->requests, $controller->emitted];
    }
}
