--TEST--
AOT: sscanf() integer out-arg and two-arg + sign (issues #3190, #4201)
--FILE--
<?php
$n = 0;
sscanf('42', '%d', $n);
echo $n, "\n";
$r = sscanf('+42', '%d');
echo isset($r[0]) ? (string) $r[0] : 'null', "\n";
--EXPECT--
42
42
