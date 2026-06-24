--TEST--
stdlib join()/implode() — null separator coerces to empty string (#11013, ext/standard/string.c)
--FILE--
<?php
echo join(null, ['a', 'b']), "\n";
echo implode(null, ['a', 'b']), "\n";
--EXPECT--
ab
ab
