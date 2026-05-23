--TEST--
AOT: array_unique() strict identity on packed lists
--FILE--
<?php
$a = array(1, 2, 1, 3, 2);
$ua = array_unique($a);
echo count($ua), "\n";
--EXPECT--
3
