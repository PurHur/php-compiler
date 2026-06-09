--TEST--
stdlib soundex()/metaphone() JIT — weak scalar coercion and array TypeError (#4193)
--JIT--
--FILE--
<?php
echo soundex(123), "\n";
echo metaphone(true), "\n";

try {
    soundex([]);
} catch (Throwable $e) {
    echo 'soundex array: ', get_class($e), "\n";
}
--EXPECT--
0000

soundex array: TypeError
