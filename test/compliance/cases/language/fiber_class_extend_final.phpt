--TEST--
class cannot extend final Fiber (php-src Zend/zend_fibers.stub.php; #28389)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
class BadFiber extends Fiber {}
echo "EXTENDED_OK\n";
?>
--EXPECT_EXIT--
255
--EXPECTREGEX--
Class BadFiber cannot extend final class Fiber
