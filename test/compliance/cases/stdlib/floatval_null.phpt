--TEST--
stdlib floatval() for null (VM)
--FILE--
<?php
echo floatval(null), "\n";
--EXPECT--
0
