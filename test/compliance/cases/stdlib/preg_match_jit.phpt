--TEST--
stdlib preg_match() JIT match count
--FILE--
<?php
echo preg_match('#(\d+)#', 'id42'), "\n";
echo preg_match('#(\d+)#', 'no-digits'), "\n";
echo preg_match('#^/post/(\d+)$#', '/post/7'), "\n";
--EXPECT--
1
0
1
