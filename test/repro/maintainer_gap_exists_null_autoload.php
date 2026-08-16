<?php

/**
 * #31443 — class/interface/trait/enum_exists(null $autoload) soft-null DEP + bool result.
 *
 * php-src: Zend/zend_builtin_functions.c — zif_*_exists Z_PARAM_BOOL autoload
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
    'class_exists' => ['stdClass', null],
    'interface_exists' => ['Traversable', null],
    'trait_exists' => ['NoSuchTrait', null],
    'enum_exists' => ['NoSuchEnum', null],
] as $fn => $args) {
    try {
        $r = $fn(...$args);
        echo $fn, '=', var_export($r, true), "\n";
    } catch (Throwable $e) {
        echo $fn, ' THREW ', get_class($e), ': ', $e->getMessage(), "\n";
    }
}
