<?php
/**
 * Maintainer repro for #16488 — date_create() parse failure must return false silently.
 *
 * php-src: ext/date/php_date.c — PHP_FUNCTION(date_create)
 */

$warnings = 0;
set_error_handler(static function () use (&$warnings): bool {
    ++$warnings;

    return true;
});

foreach (['date_create', 'date_create_immutable'] as $fn) {
    $warnings = 0;
    $result = $fn('not-a-date');
    if (false !== $result) {
        fwrite(STDERR, "{$fn}(): expected false, got ".var_export($result, true)."\n");
        exit(1);
    }
    if (0 !== $warnings) {
        fwrite(STDERR, "{$fn}(): expected 0 warnings, got {$warnings}\n");
        exit(1);
    }
    echo "{$fn}:ok\n";
}

$errors = DateTime::getLastErrors();
if (!\is_array($errors) || 0 === ($errors['error_count'] ?? 0)) {
    fwrite(STDERR, 'DateTime::getLastErrors(): expected parse error array'."\n");
    exit(1);
}
echo "getLastErrors:ok\n";
