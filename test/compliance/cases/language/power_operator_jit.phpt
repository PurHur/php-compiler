--TEST--
Power operator (**) under JIT via pow() stdlib
--FILE--
<?php
echo intval(2 ** 10), "\n";
echo intval(3 ** 2), "\n";
--EXPECT--
1024
9
