--TEST--
stdlib preg_match() basic match
--FILE--
<?php
echo preg_match('#(\d+)#', 'id42'), "\n";
--EXPECT--
1
