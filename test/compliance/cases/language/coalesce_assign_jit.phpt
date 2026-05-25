--TEST--
Language: null coalescing assignment (??=) JIT
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

$_GET['missing'] ??= 'from-get';
echo $_GET['missing'], "\n";
--ENV--
QUERY_STRING=
--EXPECT--
default
set
home
from-get
