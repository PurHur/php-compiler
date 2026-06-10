--TEST--
stdlib strncmp()/strspn()/strcspn() — numeric-string length/offset (#5103, ext/standard/string.c)
--FILE--
<?php
echo strncmp('a', 'b', '1'), "\n";
echo strspn('abc', 'ab', '0', '2'), "\n";
echo strcspn('abc', 'b', '1', '2'), "\n";
echo strncasecmp('a', 'B', '1'), "\n";
try {
    strncmp('a', 'b', 'x');
} catch (TypeError $e) {
    echo 'strncmp TypeError', "\n";
    echo $e->getMessage(), "\n";
}
--EXPECT--
-1
2
0
-1
strncmp TypeError
strncmp(): Argument #3 ($length) must be of type int, string given
