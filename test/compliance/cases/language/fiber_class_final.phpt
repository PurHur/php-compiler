--TEST--
Fiber ReflectionClass::isFinal() (php-src Zend/zend_fibers.stub.php; #28389)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
echo (new ReflectionClass(Fiber::class))->isFinal() ? "fiber_final_yes\n" : "fiber_final_no\n";
echo (new ReflectionClass(FiberError::class))->isFinal() ? "fibererror_final_yes\n" : "fibererror_final_no\n";
?>
--EXPECT--
fiber_final_yes
fibererror_final_yes
