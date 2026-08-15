<?php

/**
 * Repro #30602 — ctype_* excess argc → ArgumentCountError (not LogicException).
 * php-src: ext/ctype/ctype.c
 */
$fns = [
    'ctype_alnum',
    'ctype_alpha',
    'ctype_cntrl',
    'ctype_digit',
    'ctype_graph',
    'ctype_lower',
    'ctype_print',
    'ctype_punct',
    'ctype_space',
    'ctype_upper',
    'ctype_xdigit',
];
foreach ($fns as $f) {
    try {
        $f('a', 1);
        echo $f, " excess:NO_THROW\n";
    } catch (Throwable $e) {
        echo $f, ' excess:', get_class($e), ':', $e->getMessage(), "\n";
    }
    try {
        $f();
        echo $f, " missing:NO_THROW\n";
    } catch (Throwable $e) {
        echo $f, ' missing:', get_class($e), ':', $e->getMessage(), "\n";
    }
}
echo 'ok=', (ctype_alnum('a') && !ctype_alnum('!')) ? '1' : '0', "\n";
