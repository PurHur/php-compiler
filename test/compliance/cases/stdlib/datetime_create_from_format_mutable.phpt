--TEST--
stdlib DateTime::createFromFormat() mutable class (#9921, ext/date/php_datetime.c)
--FILE--
<?php
$dt = DateTime::createFromFormat('Y', 'notadate');
var_export($dt);
echo "\n";
$errors = DateTime::getLastErrors();
var_export(is_array($errors));
echo "\n";
echo (string) $errors['error_count'], "\n";

$ok = DateTime::createFromFormat('Y-m-d', '2024-06-05');
var_export($ok !== false);
echo "\n";
echo $ok->format('Y-m-d'), "\n";
echo get_class($ok), "\n";
var_export(DateTime::getLastErrors());
echo "\n";
?>
--EXPECT--
PHP Warning:  DateTime::createFromFormat(): Failed to parse time string (notadate)
false
true
2
true
2024-06-05
DateTime
false
