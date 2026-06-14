--TEST--
stdlib mb_strwidth() / mb_strimwidth() — display width (issue #3495, ext/mbstring/mbstring.c)
--FILE--
<?php
echo function_exists('mb_strwidth') ? 'width:yes' : 'width:no', "\n";
echo function_exists('mb_strimwidth') ? 'trim:yes' : 'trim:no', "\n";
echo mb_strwidth("あa", 'UTF-8'), "\n";
echo mb_strwidth("\xE3\x81\x82", 'UTF-8'), "\n";
echo mb_strwidth("\xE2\x97\x86", 'UTF-8'), "\n";
echo mb_strimwidth("あいう", 0, 4, '', 'UTF-8'), "|", "\n";
echo mb_strimwidth("あいう", 0, 4, '..', 'UTF-8'), "|", "\n";
echo mb_strimwidth('hello', 0, 3, '..'), "|", "\n";
echo mb_strimwidth('hello', 0, 2, '..'), "|", "\n";
echo mb_strimwidth("あいう", 1, 4, '', 'UTF-8'), "|", "\n";
try {
    mb_strimwidth('abc', 5, 1);
} catch (ValueError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
width:yes
trim:yes
3
2
1
あい|
あ..|
h..|
..|
いう|
mb_strimwidth(): Argument #2 ($start) is out of range
