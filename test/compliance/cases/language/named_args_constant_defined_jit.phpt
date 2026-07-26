--TEST--
constant/defined named name/constant_name arguments (JIT, issue #23434)
--FILE--
<?php
define('K', 1);
echo constant(name: 'K'), PHP_EOL;
echo defined(constant_name: 'K') ? 'Y' : 'N', PHP_EOL;
--EXPECT--
1
Y
