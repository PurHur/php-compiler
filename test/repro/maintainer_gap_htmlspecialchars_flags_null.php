<?php

/**
 * Repro for #24696: htmlspecialchars()/htmlentities() null $flags → TypeError.
 *
 * Zend 8.2+: TypeError for null $flags (int parameter, not ?int).
 * This compiler: coerces null to 0 and returns encoded output.
 */

$pass = 0;
$fail = 0;

foreach ([
    ['htmlspecialchars', ['a', null]],
    ['htmlentities', ['a', null]],
    ['htmlspecialchars_decode', ['&amp;', null]],
    ['html_entity_decode', ['&amp;', null]],
] as [$fn, $args]) {
    try {
        $result = $fn(...$args);
        echo "FAIL: {$fn}() did not throw TypeError for null flags — got: " . var_export($result, true) . "\n";
        $fail++;
    } catch (\TypeError $e) {
        if (str_contains($e->getMessage(), 'flags') && str_contains($e->getMessage(), 'null')) {
            echo "PASS: {$fn}() threw TypeError: {$e->getMessage()}\n";
            $pass++;
        } else {
            echo "FAIL: {$fn}() threw TypeError but wrong message: {$e->getMessage()}\n";
            $fail++;
        }
    }
}

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
