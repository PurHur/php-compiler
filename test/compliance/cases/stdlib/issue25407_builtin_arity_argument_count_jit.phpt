--TEST--
stdlib arity JIT — ArgumentCountError not LogicException (#25407, Zend zend_API.c)
--FILE--
<?php
$cases = [
    'str_replace' => static function () { str_replace('a', 'b'); },
    'substr_replace' => static function () { substr_replace('hello', 'X'); },
    'preg_replace' => static function () { preg_replace('/x/'); },
    'preg_filter' => static function () { preg_filter('/a/'); },
    'preg_replace_callback' => static function () { preg_replace_callback('/a/'); },
    'password_hash' => static function () { password_hash('x'); },
    'fgetcsv' => static function () { fgetcsv(); },
    'fputcsv' => static function () { fputcsv(); },
];
foreach ($cases as $name => $fn) {
    try {
        $fn();
        echo $name, " ran\n";
    } catch (ArgumentCountError $e) {
        echo $name, ' ArgumentCountError: ', $e->getMessage(), "\n";
    } catch (Throwable $e) {
        echo $name, ' ', get_class($e), ': ', $e->getMessage(), "\n";
    }
}
?>
--EXPECT--
str_replace ArgumentCountError: str_replace() expects at least 3 arguments, 2 given
substr_replace ArgumentCountError: substr_replace() expects at least 3 arguments, 2 given
preg_replace ArgumentCountError: preg_replace() expects at least 3 arguments, 1 given
preg_filter ArgumentCountError: preg_filter() expects at least 3 arguments, 1 given
preg_replace_callback ArgumentCountError: preg_replace_callback() expects at least 3 arguments, 1 given
password_hash ArgumentCountError: password_hash() expects at least 2 arguments, 1 given
fgetcsv ArgumentCountError: fgetcsv() expects at least 1 argument, 0 given
fputcsv ArgumentCountError: fputcsv() expects at least 2 arguments, 0 given
