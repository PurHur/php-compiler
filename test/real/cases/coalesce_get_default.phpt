--TEST--
Web: $_GET['key'] ?? default without undefined-key warning (issues #99, #273)
--ENV--
QUERY_STRING=
--FILE--
<?php
$name = $_GET['name'] ?? 'Guest';
echo $name, "\n";
--EXPECT--
Guest
