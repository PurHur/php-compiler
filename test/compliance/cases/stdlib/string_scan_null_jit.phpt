--TEST--
stdlib string scan builtins — null string operands TypeError JIT (#18254, ext/standard/string.c)
--JIT--
--FILE--
<?php
try {
    count_chars(null);
    echo "count_chars: uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
try {
    strspn('abc', null);
    echo "strspn: uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
try {
    strcspn('abc', null);
    echo "strcspn: uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
count_chars(): Argument #1 ($string) must be of type string, null given
strspn(): Argument #2 ($characters) must be of type string, null given
strcspn(): Argument #2 ($characters) must be of type string, null given
