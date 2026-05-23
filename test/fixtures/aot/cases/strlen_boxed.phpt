--TEST--
AOT strlen() on boxed superglobal string
--ENV--
QUERY_STRING=id=Ada
--FILE--
<?php
$id = $_GET['id'] ?? '';
echo strlen($id), "\n";
--EXPECT--
3
