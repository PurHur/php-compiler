--TEST--
stdlib next/prev/current on cast stdClass after by-ref pointer (#25097)
--FILE--
<?php
error_reporting(E_ALL & ~E_DEPRECATED);
// Short script: sole live ref is $o2; by-ref next() must not releaseDirectObject the CV.
$o2 = (object)['a' => 1, 'b' => 2];
next($o2);
echo 'next_current=' . var_export(current($o2), true) . ' key=' . var_export(key($o2), true) . "\n";

$o = (object)['a' => 1, 'b' => 2, 'c' => 3];
end($o);
$r = prev($o);
echo 'prev_ret=' . var_export($r, true)
   . ' current=' . var_export(current($o), true)
   . ' key=' . var_export(key($o), true) . "\n";
--EXPECT--
next_current=2 key='b'
prev_ret=2 current=2 key='b'
