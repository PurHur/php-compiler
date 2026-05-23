--TEST--
AOT: preg_match_all() match count
--FILE--
<?php
echo preg_match_all('#(\d+)#', 'a1b22c333'), "\n";
echo preg_match_all('#(\d+)#', 'no-digits'), "\n";
--EXPECT--
3
0
