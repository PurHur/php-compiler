--TEST--
stdlib is_array() and is_string() on superglobal coalesce results
--ENV--
QUERY_STRING=name=Ada
--FILE--
<?php
$name = $_GET['name'] ?? '';
echo is_array($name) ? 'ay' : 'an', "\n";
echo is_string($name) ? 'sy' : 'sn', "\n";
echo is_array([]) ? 'ay' : 'an', "\n";
--EXPECT--
an
sy
ay
