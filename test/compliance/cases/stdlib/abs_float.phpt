--TEST--
stdlib abs() for floats
--FILE--
<?php
echo abs(-2.5), "\n";
echo abs(3.25), "\n";
--EXPECT--
2.5
3.25
