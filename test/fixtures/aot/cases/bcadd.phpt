--TEST--
AOT: bcadd() smoke (#6100)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
echo bcadd('1.234', '5', 2), "\n";
--EXPECT--
6.23
