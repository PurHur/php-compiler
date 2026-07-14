--TEST--
stdlib iconv() JIT — null encoding operands coerce to default charset (#18944, ext/iconv/iconv.c)
--FILE--
<?php
echo iconv(null, 'UTF-8', 'hi'), "\n";
echo iconv('UTF-8', null, 'hi'), "\n";
?>
--EXPECT--
hi
hi
