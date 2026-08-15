--TEST--
stdlib getenv() — bool/int name operands TypeError (#17765, ext/standard/basic_functions.c)
--FILE--
<?php
declare(strict_types=1);

foreach ([false, 0, 123] as $n) {
    try {
        var_export(getenv($n));
    } catch (TypeError $e) {
        echo $e->getMessage(), "\n";
    }
}
putenv('123=abc');
try {
    var_export(getenv(123));
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
getenv(): Argument #1 ($name) must be of type ?string, bool given
getenv(): Argument #1 ($name) must be of type ?string, int given
getenv(): Argument #1 ($name) must be of type ?string, int given
getenv(): Argument #1 ($name) must be of type ?string, int given
