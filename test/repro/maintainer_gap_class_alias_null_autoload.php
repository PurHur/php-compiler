<?php

/**
 * class_alias($class, $alias, null) without strict_types — Zend DEP+true; VM LogicException.
 * php-src: Zend/zend_builtin_functions.c PHP_FUNCTION(class_alias)
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

class MaintainerGapClassAliasProbe {}

try {
    var_export(class_alias(MaintainerGapClassAliasProbe::class, 'MaintainerGapClassAliasProbeAlias', null));
    echo "\n";
    var_export(class_exists('MaintainerGapClassAliasProbeAlias', false));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
