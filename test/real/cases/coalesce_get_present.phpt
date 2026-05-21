--TEST--
Web: $_GET['key'] ?? default when key is present (issues #99, #148)
--ENV--
QUERY_STRING=name=Ada
--FILE--
<?php
$name = $_GET['name'] ?? 'Guest';
echo $name, "\n";
--EXPECT--
Ada
