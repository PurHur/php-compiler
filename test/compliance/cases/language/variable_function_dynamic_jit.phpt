--TEST--
Variable function call with runtime builtin name from $_GET (issue #1997)
--ENV--
QUERY_STRING=op=strlen
--FILE--
<?php
$name = $_GET['op'] ?? 'strlen';
echo $name('hi'), "\n";
--EXPECT--
2
