<?php

/**
 * Repro #30784 — get_extension_funcs() excess argc → ArgumentCountError.
 * php-src: ext/standard/basic_functions.c
 */
foreach ([
    'hi' => static fn () => get_extension_funcs('standard', 1),
    'lo' => static fn () => get_extension_funcs(),
] as $name => $call) {
    try {
        $call();
        echo $name, ":NO_THROW\n";
    } catch (Throwable $e) {
        echo $name, ':', get_class($e), ':', $e->getMessage(), "\n";
    }
}
$funcs = get_extension_funcs('standard');
echo 'ok=', is_array($funcs) ? '1' : '0', "\n";
