--TEST--
stdlib DateTimeImmutable::createFromFormat() returns false on parse failure (#9920)
--FILE--
<?php
$dt = DateTimeImmutable::createFromFormat('Y', 'notadate');
var_export($dt);
echo "\n";
$errors = DateTimeImmutable::getLastErrors();
var_export(is_array($errors));
echo "\n";
echo (string) $errors['error_count'], "\n";

$ok = DateTimeImmutable::createFromFormat('Y-m-d', '2024-06-05');
var_export($ok !== false);
echo "\n";
var_export(DateTimeImmutable::getLastErrors());
echo "\n";
?>
--EXPECT--
PHP Warning:  DateTimeImmutable::createFromFormat(): Failed to parse time string (notadate)
false
true
2
true
false
