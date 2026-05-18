--TEST--
Power operator (**) for integers and floats
--FILE--
<?php
echo 2 ** 10, "\n";
echo 2 ** 0.5, "\n";
echo 3 ** 2, "\n";
--EXPECT--
1024
1.4142135623731
9
