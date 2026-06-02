--TEST--
AOT: array_key_exists() null key coerces to empty string
--FILE--
<?php
$a = array();
$a[''] = 42;
echo array_key_exists(null, $a) ? "yes\n" : "no\n";
$key = null;
$b = array();
$b[''] = 7;
echo array_key_exists($key, $b) ? "yes\n" : "no\n";
--EXPECT--
yes
yes
