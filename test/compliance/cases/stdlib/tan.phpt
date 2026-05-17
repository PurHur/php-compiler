--TEST--
stdlib tan()
--FILE--
<?php
echo tan(0), "\n";
echo intval(tan(deg2rad(45)) * 1000), "\n";
--EXPECT--
0
999
