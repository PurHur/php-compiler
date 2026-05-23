--TEST--
stdlib strlen() JIT on boxed superglobal string
--ENV--
QUERY_STRING=id=Ada
--FILE--
<?php
$id = $_GET['id'] ?? '';
echo strlen($id), "\n";
echo strlen(''), "\n";
--EXPECT--
3
0
