--TEST--
stdlib fmod() for integers and floats
--FILE--
<?php
echo fmod(5, 2), "\n";
echo fmod(5.5, 2), "\n";
echo fmod(7, 3.5), "\n";
--EXPECT--
1
1.5
0
