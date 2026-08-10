<?php
/**
 * #29658 — non-alnum / empty string ++/-- under PHP_COMPILER_PROFILE=8.4
 *
 * Zend 8.4: E_DEPRECATED + no peri-mutate of non-alnum; ''++ → '1'; ''-- → -1.
 *
 *   PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/maintainer_run_20260810c/lang_inc_non_alnum.php
 *   PHP_COMPILER_PROFILE=8.4 php bin/jit.php test/repro/maintainer_run_20260810c/lang_inc_non_alnum.php
 */
error_reporting(E_ALL);
set_error_handler(static function (int $errno, string $errstr): bool {
    if ($errno === \E_DEPRECATED) {
        echo "DEP:$errstr\n";

        return true;
    }

    return false;
});

function show(string $label, string $v): void
{
    $copy = $v;
    $copy++;
    echo $label, '=', var_export($copy, true), "\n";
}

function showDec(string $label, string $v): void
{
    $copy = $v;
    $copy--;
    echo $label, '=', var_export($copy, true), "\n";
}

show('empty', '');
show('space', ' ');
show('a-dash', 'a-');
show('Z', 'Z');
show('12', '12');
showDec('emptyDec', '');
showDec('spaceDec', ' ');
