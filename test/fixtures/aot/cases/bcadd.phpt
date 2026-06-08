--TEST--
AOT: bcadd() smoke (#6100)
--FILE--
<?php
echo bcadd('1.234', '5', 2), "\n";
--EXPECT--
6.23
