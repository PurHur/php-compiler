--TEST--
stdlib StringTrimMode phantom absent; trim arity ≤2 (#28202 / #28230, re-#7283)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
var_export(enum_exists('StringTrimMode', false));
echo "\n";
echo trim('  x  '), "\n";
echo trim('xxhelloxx', 'x'), "\n";
try {
    trim(' x ', ' ', true);
    echo "uncaught\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
enum Es: string { case B = 'hi'; }
try {
    trim('  x  ', Es::B);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
false
x
hello
ArgumentCountError:trim() expects at most 2 arguments, 3 given
trim(): Argument #2 ($characters) must be of type string, Es given
