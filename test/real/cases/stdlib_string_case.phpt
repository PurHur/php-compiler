--TEST--
Integration: lcfirst, ucfirst, strtolower, strtoupper, trim
--FILE--
<?php
echo trim("  ab  "), "\n";
echo strtolower("Hi"), "\n";
echo strtoupper(lcfirst("WORLD")), "\n";
--EXPECT--
ab
hi
WORLD
