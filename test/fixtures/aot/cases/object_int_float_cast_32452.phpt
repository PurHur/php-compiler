--TEST--
AOT: (int)/(float) native object must verify and match Zend IS_OBJECT (#32452)
--FILE--
<?php
$o = new stdClass();
echo (int) $o;
echo "\n";
echo (float) $o;
echo "\n";
echo (int) (new stdClass());
echo "\n";
--EXPECT--
1
1
1
--EXPECT_EXIT--
0
