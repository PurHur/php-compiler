--TEST--
stdlib long2ip() uint32 wrap JIT (issue #9297)
--FILE--
<?php
$a = long2ip(-1);
$b = long2ip(4294967296);
echo $a === '255.255.255.255' ? "wrap-neg\n" : "no-wrap-neg\n";
echo $b === '0.0.0.0' ? "wrap-over\n" : "no-wrap-over\n";
--EXPECT--
wrap-neg
wrap-over
