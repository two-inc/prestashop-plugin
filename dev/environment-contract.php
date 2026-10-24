<?php

/**
 * Where this plugin declares the Environment setting (TWO-26613). Read only
 * by this repo's dev/environment-parity.php, which extracts the contract from
 * this repo's own code into dev/environment-contract.json; the other plugins
 * compare that JSON as data. After changing this file or the code it names,
 * run `php dev/environment-parity.php --write` and commit the JSON. Runs
 * inside that script and uses its helpers; declare no functions or classes.
 */

return [
    'platform' => 'prestashop',
    'cases' => 'tests/fixtures/environment-cases.json',
    'helpers' => [
        'file' => 'twopayment.php',
        'class' => 'Twopayment',
        'consts' => ['modes' => 'TWO_ENVIRONMENTS', 'default' => 'TWO_ENVIRONMENT_DEFAULT'],
        'methods' => [
            'isTwoInternalEnvironmentsEnabled',
            'recogniseTwoEnvironment',
            'normaliseTwoEnvironment',
            'getTwoEnvironmentOptions',
        ],
        'options' => 'getTwoEnvironmentOptions',
        'read' => 'normaliseTwoEnvironment',
    ],
    // upgrade-2.7.8.php rewrites every stored row, at every shop tier,
    // through the read path; a missing global row is written as sandbox.
    'migrate' => [
        'method' => 'normaliseTwoEnvironment',
        'variants' => ['every shop' => ['args' => [], 'moves' => []]],
    ],
    'declarations' => static function (array $p): array {
        $field = match1(
            "/'label'\\s*=>\\s*\\\$this->l\\(\\s*" . STR . "\\s*\\),\\s*'name'\\s*=>\\s*'PS_TWO_ENVIRONMENT',"
            . "\\s*'desc'\\s*=>\\s*\\\$this->l\\(\\s*" . STR . "\\s*\\)/",
            $p['file']('twopayment.php'),
            'the PS_TWO_ENVIRONMENT HelperForm input'
        );
        $install = match1(
            "/Configuration::updateValue\\(\\s*'PS_TWO_ENVIRONMENT'\\s*,\\s*([^)]+?)\\s*\\)/",
            $p['method']('twopayment.php', 'Twopayment', 'installTwoSettings'),
            'the PS_TWO_ENVIRONMENT write in installTwoSettings()'
        );
        return [
            'install_default' => [$p['value']($install[1])],
            'labels' => labels($p['method']('twopayment.php', 'Twopayment', 'getTwoEnvironmentLabel')),
            'field_label' => [unquote($field[1])],
            'description' => [unquote($field[2])],
        ];
    },
];
