--TEST--
AOT: $a['k']=null inserts key (array_key_exists) — #21947
--FILE--
<?php
$a = [];
$a['z'] = null;
echo array_key_exists('z', $a) ? "yes\n" : "no\n";
$x = null;
$b = [];
$b['z'] = $x;
echo array_key_exists('z', $b) ? "yes\n" : "no\n";
$c = [];
$c[0] = null;
echo array_key_exists(0, $c) ? "yes\n" : "no\n";
--EXPECT--
yes
yes
yes
