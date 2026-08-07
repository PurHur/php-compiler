--TEST--
class cannot extend final FiberError (php-src Zend/zend_fibers.stub.php; #28389)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
class BadFiberError extends FiberError {}
echo "EXTENDED_OK\n";
?>
--EXPECT_EXIT--
255
--EXPECTREGEX--
Class BadFiberError cannot extend final class FiberError
