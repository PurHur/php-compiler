--TEST--
ReflectionFiber::getExecutingFiber is not a php-src API (#25058, re-#6793)
--FILE--
<?php
echo 'method=', method_exists('ReflectionFiber', 'getExecutingFiber') ? '1' : '0', "\n";
--EXPECT--
method=0
