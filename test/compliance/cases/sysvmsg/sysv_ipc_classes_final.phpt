--TEST--
SysvMessageQueue/SysvSemaphore/SysvSharedMemory ReflectionClass::isFinal() (php-src stubs; #28422)
--FILE--
<?php
foreach (['SysvMessageQueue', 'SysvSemaphore', 'SysvSharedMemory'] as $c) {
    echo $c, ' ', (new ReflectionClass($c))->isFinal() ? "final_yes\n" : "final_no\n";
}
?>
--EXPECT--
SysvMessageQueue final_yes
SysvSemaphore final_yes
SysvSharedMemory final_yes
