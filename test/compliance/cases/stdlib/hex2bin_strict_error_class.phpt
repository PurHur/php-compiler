--TEST--
stdlib hex2bin() — strict odd length throws Error not ValueError (#10072, ext/standard/string.c)
--FILE--
<?php
try {
    hex2bin('abc', true);
    echo "no throw\n";
} catch (ValueError $e) {
    echo "ValueError\n";
} catch (Error $e) {
    echo 'Error:', $e->getMessage(), "\n";
}
--EXPECT--
Error:Hexadecimal input string must have an even length
