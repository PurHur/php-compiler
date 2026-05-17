--TEST--
stdlib deg2rad() for integers and floats
--FILE--
<?php
echo intval(deg2rad(180) * 1000), "\n";
echo intval(deg2rad(90) * 1000), "\n";
echo intval(deg2rad(45.0) * 1000), "\n";
--EXPECT--
3141
1570
785
