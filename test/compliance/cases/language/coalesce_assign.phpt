--TEST--
Language: null coalescing assignment (??=)
--FILE--
<?php
$a = null;
$a ??= 'default';
echo $a, "\n";

$b = 'set';
$b ??= 'ignored';
echo $b, "\n";

$items = [];
$items['page'] ??= 'home';
echo $items['page'], "\n";

$items['page'] ??= 'other';
echo $items['page'], "\n";

$_GET['missing'] ??= 'from-get';
echo $_GET['missing'], "\n";
--ENV--
QUERY_STRING=
--EXPECT--
default
set
home
home
from-get
