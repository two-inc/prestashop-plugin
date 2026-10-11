<?php

/**
 * The order-time guard behind dev/check-order-time-untouched.sh (TWO-26153). Reads `git diff -U0` of twopayment.php
 * on stdin, maps every changed line, on both sides, to the class member that encloses it (token_get_all(), so a
 * docblock or comment belongs to the member it heads), and fails on any line outside the allowed set:
 *   - the admin methods this change edits (ALLOWED_METHODS)
 *   - methods and constants that do not exist on the base side
 *   - the require_once of classes/TwoTaxCodeSection.php
 *   - blank lines between members
 *   - the version line the version-bump workflow commits
 *
 * Usage: git diff -U0 <base> -- twopayment.php | php dev/order-time-guard.php <base file> <head file>
 */

const ALLOWED_METHODS = array(
    'getTwoTaxCodeOptions',
    'getTwoTaxCodeMapInputs',
    'getTwoTaxCodeMapRows',
    'getPostedTwoTaxCodeMap',
    'validTwoOrderManagementFormValues',
    'saveTwoOrderManagementFormValues',
    'twoCheckoutVisibilityRow',
    'uninstallTwoSettings',
    // Admin only, deleted: the old dropdown's option label, which the table no longer uses.
    'formatTwoTaxCodeOptionLabel',
);
const ALLOWED_REQUIRES = array("dirname(__FILE__) . '/classes/TwoTaxCodeSection.php'");
// The version line .github/workflows/version-bump.yml commits onto every PR into staging.
const ALLOWED_LINE = '/^\s*\$this->version = \'[0-9A-Za-z.+-]+\';$/';

/**
 * @param string $code
 * @return array{0: array<int,string>, 1: array<string,true>} line => member label, and the set of labels
 */
function orderTimeGuardMembers($code)
{
    $tokens = token_get_all($code);
    $owner = array();
    $labels = array();
    $depth = 0;
    $count = count($tokens);
    $line = 1;
    $lines = array();
    foreach ($tokens as $i => $token) {
        $lines[$i] = $line;
        $text = is_array($token) ? $token[1] : $token;
        $line += substr_count($text, "\n");
    }
    $endLine = static function ($i) use ($tokens, $lines) {
        $text = is_array($tokens[$i]) ? $tokens[$i][1] : $tokens[$i];

        return $lines[$i] + substr_count(rtrim($text, "\n"), "\n");
    };
    // The first line of a member: its modifiers and the comments directly above it.
    $startOf = static function ($i) use ($tokens, $lines) {
        $start = $lines[$i];
        for ($j = $i - 1; $j >= 0; --$j) {
            $t = $tokens[$j];
            if (!is_array($t)) {
                break;
            }
            if ($t[0] === T_WHITESPACE) {
                if (substr_count($t[1], "\n") > 1) {
                    break;
                }
                continue;
            }
            if (in_array($t[0], array(T_PUBLIC, T_PROTECTED, T_PRIVATE, T_STATIC, T_ABSTRACT, T_FINAL, T_COMMENT, T_DOC_COMMENT, T_VAR), true)) {
                $start = $lines[$j];
                continue;
            }
            break;
        }

        return $start;
    };
    for ($i = 0; $i < $count; ++$i) {
        $t = $tokens[$i];
        if ($t === '{' || (is_array($t) && in_array($t[0], array(T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES), true))) {
            ++$depth;
            continue;
        }
        if ($t === '}') {
            --$depth;
            continue;
        }
        if (!is_array($t)) {
            continue;
        }
        $label = null;
        $stop = null;
        if ($depth === 1 && $t[0] === T_FUNCTION) {
            for ($j = $i + 1; $j < $count && !(is_array($tokens[$j]) && $tokens[$j][0] === T_STRING); ++$j) {
            }
            $label = 'method ' . $tokens[$j][1];
            $stop = '{';
        } elseif ($depth === 1 && $t[0] === T_CONST) {
            for ($j = $i + 1; $j < $count && !(is_array($tokens[$j]) && $tokens[$j][0] === T_STRING); ++$j) {
            }
            $label = 'const ' . $tokens[$j][1];
            $stop = ';';
        } elseif ($depth === 1 && $t[0] === T_VARIABLE) {
            $label = 'property ' . $t[1];
            $stop = ';';
        } elseif ($depth === 0 && in_array($t[0], array(T_REQUIRE_ONCE, T_REQUIRE, T_INCLUDE, T_INCLUDE_ONCE), true)) {
            $expr = '';
            for ($j = $i + 1; $j < $count && $tokens[$j] !== ';'; ++$j) {
                $expr .= is_array($tokens[$j]) ? $tokens[$j][1] : $tokens[$j];
            }
            $label = 'require ' . trim($expr);
            $stop = ';';
        }
        if ($label === null) {
            continue;
        }
        $start = $startOf($i);
        // Run to the member's end: the ';' of a statement, or the brace closing a method body.
        $d = 0;
        for ($j = $i + 1; $j < $count; ++$j) {
            $u = $tokens[$j];
            if ($stop === ';' && $u === ';' && $d === 0) {
                break;
            }
            if ($u === '{' || (is_array($u) && in_array($u[0], array(T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES), true))) {
                ++$d;
            } elseif ($u === '}') {
                --$d;
                if ($stop === '{' && $d === 0) {
                    break;
                }
            } elseif ($stop === '{' && $u === ';' && $d === 0) {
                break; // an abstract or interface method
            } elseif ($u === '(') {
                ++$d;
            } elseif ($u === ')') {
                --$d;
            }
        }
        $end = $endLine(min($j, $count - 1));
        for ($l = $start; $l <= $end; ++$l) {
            $owner[$l] = $label;
        }
        $labels[$label] = true;
        if ($stop === '{') {
            // Past the body, whose braces balance: nothing inside a method is a member.
            $i = $j;
        }
    }

    return array($owner, $labels);
}

if ($argc < 3) {
    fwrite(STDERR, "usage: git diff -U0 <base> -- twopayment.php | php dev/order-time-guard.php <base file> <head file>\n");
    exit(2);
}
$baseCode = file_get_contents($argv[1]);
$headCode = file_get_contents($argv[2]);
list($baseOwner, $baseLabels) = orderTimeGuardMembers($baseCode);
list($headOwner, $headLabels) = orderTimeGuardMembers($headCode);
$baseLines = explode("\n", $baseCode);
$headLines = explode("\n", $headCode);

$allowed = static function ($label, $side) use ($baseLabels) {
    if ($label === null) {
        return false;
    }
    if (strpos($label, 'method ') === 0) {
        $name = substr($label, 7);

        return in_array($name, ALLOWED_METHODS, true) || ($side === 'head' && !isset($baseLabels[$label]));
    }
    if (strpos($label, 'const ') === 0) {
        return $side === 'head' && !isset($baseLabels[$label]);
    }
    if (strpos($label, 'require ') === 0) {
        return $side === 'head' && !isset($baseLabels[$label]) && in_array(substr($label, 8), ALLOWED_REQUIRES, true);
    }

    return false;
};

$failures = array();
$checked = 0;
foreach (explode("\n", stream_get_contents(STDIN)) as $diffLine) {
    if (preg_match('/^@@ -(\d+)(?:,(\d+))? \+(\d+)(?:,(\d+))? @@/', $diffLine, $m) !== 1) {
        continue;
    }
    $sides = array(
        array('base', (int) $m[1], $m[2] === '' ? 1 : (int) $m[2], $baseOwner, $baseLines),
        array('head', (int) $m[3], !isset($m[4]) || $m[4] === '' ? 1 : (int) $m[4], $headOwner, $headLines),
    );
    foreach ($sides as $side) {
        list($name, $from, $n, $owner, $lines) = $side;
        for ($l = $from; $l < $from + $n; ++$l) {
            ++$checked;
            $label = isset($owner[$l]) ? $owner[$l] : null;
            $text = isset($lines[$l - 1]) ? $lines[$l - 1] : '';
            if (($label === null && trim($text) === '') || ($label === 'method __construct' && preg_match(ALLOWED_LINE, $text) === 1)) {
                continue;
            }
            if (!$allowed($label, $name)) {
                $failures[] = sprintf('%s:%d (%s) %s', $name, $l, $label === null ? 'outside any member' : $label, trim($text));
            }
        }
    }
}
if ($failures !== array()) {
    echo "twopayment.php: changed lines outside the admin set:\n  " . implode("\n  ", $failures) . "\n";
    exit(1);
}
echo "twopayment.php: all $checked changed line(s) are in the admin set\n";
