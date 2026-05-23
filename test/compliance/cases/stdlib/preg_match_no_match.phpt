--TEST--
stdlib preg_match() no match
--FILE--
<?php
echo preg_match('#^/post/(\d+)$#', 'not-a-route'), "\n";
echo preg_match('#^/post/(\d+)$#', '/post/abc'), "\n";
--EXPECT--
0
0
