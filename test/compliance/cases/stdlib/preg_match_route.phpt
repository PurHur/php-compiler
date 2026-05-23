--TEST--
stdlib preg_match() route match
--FILE--
<?php
echo preg_match('#^/post/(\d+)$#', '/post/99'), "\n";
--EXPECT--
1
