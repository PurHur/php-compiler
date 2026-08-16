<?php

/**
 * filter_var_array($array, null) without strict_types.
 * Zend: E_DEPRECATED + E_WARNING Unknown filter ID 0 + false;
 * VM: returns input array unchanged (no DEP).
 * php-src: ext/filter/filter.c PHP_FUNCTION(filter_var_array)
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

try {
    var_export(filter_var_array(['a' => '1'], null));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
