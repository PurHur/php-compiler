--TEST--
stdlib array null string/int key assign keeps key (#21947, Zend/zend_hash.c)
--FILE--
<?php
$a = [];
$a['z'] = null;
echo array_key_exists('z', $a) ? 'y' : 'n', "\n";
echo null === $a['z'] ? 'null' : 'other', "\n";
$x = null;
$b = [];
$b['z'] = $x;
echo array_key_exists('z', $b) ? 'y' : 'n', "\n";
$c = [];
$c[0] = null;
echo array_key_exists(0, $c) ? 'y' : 'n', "\n";
--EXPECT--
y
null
y
y
