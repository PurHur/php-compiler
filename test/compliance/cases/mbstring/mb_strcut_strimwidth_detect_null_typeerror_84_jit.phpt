--TEST--
mb_detect_encoding null TypeError on 8.4 profile JIT (#20225; strcut/strimwidth soft-null #21430)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
try {
    mb_detect_encoding(null);
    echo "mb_detect_encoding: uncaught\n";
} catch (TypeError $e) {
    echo 'mb_detect_encoding: '.$e->getMessage()."\n";
}
echo mb_strcut('abc', 1), "\n";
echo mb_strimwidth('abcdef', 0, 3, '..'), "\n";
--EXPECT--
mb_detect_encoding: mb_detect_encoding(): Argument #1 ($string) must be of type string, null given
bc
a..
