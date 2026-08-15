<?php

/**
 * Repro for #31212: htmlspecialchars()/htmlentities() null $flags under strict_types.
 *
 * Zend 8.2+: TypeError (int $flags, not nullable).
 * Pre-fix VM: E_DEPRECATED + coerce to 0 / default-ish escape.
 */

declare(strict_types=1);

$pass = 0;
$fail = 0;

foreach ([
    'htmlspecialchars',
    'htmlentities',
] as $fn) {
    try {
        if ('htmlspecialchars' === $fn) {
            $result = htmlspecialchars('<a>', null);
        } else {
            $result = htmlentities('<a>', null);
        }
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
    } catch (\Throwable $e) {
        echo "FAIL: {$fn}() threw " . get_class($e) . ": {$e->getMessage()}\n";
        $fail++;
    }
}

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
