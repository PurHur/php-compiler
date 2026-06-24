--TEST--
stdlib join()/implode() JIT — null separator coerces to empty string (#11013, ext/standard/string.c)
--JIT--
--FILE--
<?php
echo join(null, ['a', 'b']), "\n";
echo implode(null, ['a', 'b']), "\n";
--EXPECT--
ab
ab
