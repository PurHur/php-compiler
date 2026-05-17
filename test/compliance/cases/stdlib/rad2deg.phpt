--TEST--
stdlib rad2deg() for integers and floats
--FILE--
<?php
echo intval(rad2deg(pi())), "\n";
echo intval(rad2deg(pi() / 2)), "\n";
echo intval(rad2deg(pi() / 4)), "\n";
--EXPECT--
180
90
45
