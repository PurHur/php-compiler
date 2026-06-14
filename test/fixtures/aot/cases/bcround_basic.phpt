--TEST--
AOT bcround() basic literal folding (#5935)
--FILE--
<?php
echo bcround('2.5', 0), "\n";
echo bcround('-2.5', 0), "\n";
--EXPECT--
3
-3
