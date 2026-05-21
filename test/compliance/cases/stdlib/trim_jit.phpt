--TEST--
stdlib trim/ltrim/rtrim() JIT path (ASCII whitespace mask)
--FILE--
<?php
echo trim('  hi  '), "\n";
echo ltrim("\tx"), "\n";
echo rtrim("y\n"), "\n";
echo trim(''), "\n";
--EXPECT--
hi
x
y
