--TEST--
stdlib date_create()/date_create_immutable() parse failure — false without E_WARNING (#16488, ext/date/php_date.c)
--FILE--
<?php
$warnings = 0;
set_error_handler(static function () use (&$warnings): bool {
    ++$warnings;

    return true;
});

var_export(date_create('not-a-date'));
echo "\n";
echo (string) $warnings, "\n";

$warnings = 0;
var_export(date_create_immutable('not-a-date'));
echo "\n";
echo (string) $warnings, "\n";

$errors = DateTime::getLastErrors();
echo is_array($errors) ? (string) $errors['error_count'] : 'fail';
echo "\n";

$ok = date_create('2026-06-01', new DateTimeZone('UTC'));
echo $ok instanceof DateTime ? $ok->format('Y-m-d') : 'bad';
echo "\n";
?>
--EXPECT--
false
0
false
0
4
2026-06-01
