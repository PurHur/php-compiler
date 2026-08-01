<?php

/**
 * Issue #26366 — property_exists() on __PHP_Incomplete_Class must warn + return false (Zend).
 *
 * php-src: Zend/zend_builtin_functions.c — PHP_FUNCTION(property_exists)
 *          Zend/zend_object_handlers.c — incomplete object handlers
 */
class Secret
{
    public $v = 1;
}

$blob = serialize(new Secret());
$u = unserialize($blob, ['allowed_classes' => false]);

$warnings = 0;
set_error_handler(static function (int $errno, string $errstr) use (&$warnings): bool {
    if (str_contains($errstr, 'incomplete object')) {
        ++$warnings;
    }

    return true;
});

$exists = property_exists($u, 'v');
if (true === $exists || 1 !== $warnings) {
    fwrite(STDERR, "fail: property_exists incomplete expected false+1 warning, got "
        .var_export($exists, true)." warnings={$warnings}\n");
    exit(1);
}

echo "ok\n";
