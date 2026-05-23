--TEST--
AOT: preg_match() match count
--FILE--
<?php
echo preg_match('#(\d+)#', 'id42'), "\n";
echo preg_match('#(\d+)#', 'no-digits'), "\n";
--EXPECT--
1
0
