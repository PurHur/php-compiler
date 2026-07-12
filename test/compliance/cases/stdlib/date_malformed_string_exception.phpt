--TEST--
stdlib DateMalformedStringException hierarchy on forward 8.4 profile (#6048, ext/date/php_date.c)
--SKIPIF--
<?php
if (getenv('PHP_COMPILER_PROFILE') !== '8.4' && getenv('PHP_COMPILER_PROFILE') !== '8.5') {
    die('skip date exception hierarchy requires PHP_COMPILER_PROFILE=8.4');
}
?>
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
var_export(class_exists('DateMalformedStringException', false));
echo "\n";
var_export(class_exists('DateInvalidOperationException', false));
echo "\n";
var_export(is_subclass_of('DateMalformedStringException', 'DateException'));
echo "\n";
try {
    new DateTime('not-a-date');
    echo "no throw\n";
} catch (DateMalformedStringException $e) {
    echo get_class($e), "\n";
}
--EXPECT--
true
true
true
DateMalformedStringException
