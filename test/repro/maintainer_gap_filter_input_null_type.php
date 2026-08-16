<?php

/**
 * filter_input / filter_has_var / filter_input_array(null $type) without strict_types.
 * Zend: E_DEPRECATED then continue; VM: TypeError (PhpInputFilter|int).
 * php-src: ext/filter/filter.c
 */
error_reporting(E_ALL);
set_error_handler(static function (int $errno, string $errstr): bool {
    $label = match ($errno) {
        E_DEPRECATED => 'DEPRECATED',
        E_WARNING => 'WARNING',
        default => (string) $errno,
    };
    echo $label, ': ', $errstr, "\n";

    return true;
});

foreach ([
    'filter_has_var' => static fn () => var_export(filter_has_var(null, 'x'), true),
    'filter_input' => static fn () => var_export(filter_input(null, 'x'), true),
    'filter_input_array' => static fn () => var_export(filter_input_array(null), true),
] as $name => $fn) {
    echo "== $name ==\n";
    try {
        echo $fn(), "\n";
    } catch (Throwable $e) {
        echo get_class($e), ': ', $e->getMessage(), "\n";
    }
}
