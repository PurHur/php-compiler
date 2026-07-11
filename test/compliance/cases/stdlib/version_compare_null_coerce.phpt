--TEST--
stdlib version_compare() null operand coerces (#11936, ext/standard/versioning.c)
--FILE--
<?php
echo version_compare(null, '1.0'), "\n";
?>
--EXPECT--
-1
