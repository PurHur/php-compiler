--TEST--
AOT: null coalescing assignment (??=)
--FILE--
<?php
$a = null;
$a ??= 'default';
echo $a, "\n";

$items = [];
$items['page'] ??= 'home';
echo $items['page'], "\n";
--EXPECT--
default
home
