--TEST--
Language: null coalescing assignment (??=) in echo expression
--FILE--
<?php
echo $_GET['k'] ??= 'default';
echo "\n";

$a = null;
echo $a ??= 'default';
echo "\n";

$items = [];
echo $items['page'] ??= 'home';
echo "\n";
--ENV--
QUERY_STRING=
--EXPECT--
default
default
home
