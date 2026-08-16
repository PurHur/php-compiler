<?php

/**
 * #31443 — class/interface/trait/enum_exists(..., null) $autoload
 * Zend: E_DEPRECATED + coerce null→false; no crash.
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
    var_export(class_exists('stdClass', null));
    echo "\n";
    var_export(interface_exists('Traversable', null));
    echo "\n";
    var_export(trait_exists('NoSuchTrait', null));
    echo "\n";
    var_export(enum_exists('NoSuchEnum', null));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
