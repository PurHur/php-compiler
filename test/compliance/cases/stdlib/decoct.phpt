--TEST--
stdlib decoct() for non-negative integers
--FILE--
<?php
echo decoct(0), "\n";
echo decoct(8), "\n";
echo decoct(63), "\n";
echo decoct(512), "\n";
--EXPECT--
0
10
77
1000
