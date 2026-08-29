--TEST--
AOT: hexdec/bindec/octdec match Zend under HELPER_RUNTIME_O=0 (#31966)
--FILE--
<?php
echo dechex(10), "\n";
echo decoct(10), "\n";
echo decbin(10), "\n";
echo octdec('12'), "\n";
echo hexdec('ff'), "\n";
echo bindec('1010'), "\n";
--EXPECT--
a
12
1010
10
255
10
--EXPECT_EXIT--
0
