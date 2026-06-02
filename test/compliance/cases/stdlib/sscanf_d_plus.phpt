--TEST--
stdlib sscanf() — %d parses leading + sign (issue #4201)
--FILE--
<?php
$r = sscanf('+42', '%d');
echo isset($r[0]) ? (string) $r[0] : 'null', "\n";
$n = 0;
sscanf('+42', '%d', $n);
echo $n, "\n";
--EXPECT--
42
42
