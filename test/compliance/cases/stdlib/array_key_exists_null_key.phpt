--TEST--
stdlib array_key_exists() null key coerces to empty string (php-src parity)
--FILE--
<?php
$a = array('' => 1);
echo array_key_exists(null, $a) ? 'y' : 'n', "\n";
echo array_key_exists('', $a) ? 'y' : 'n', "\n";
$b = array(10, 20);
echo array_key_exists(null, $b) ? 'y' : 'n', "\n";
$key = null;
$c = array();
$c[''] = 99;
echo array_key_exists($key, $c) ? 'y' : 'n', "\n";
--EXPECT--
y
y
n
y
