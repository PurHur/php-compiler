--TEST--
Shmop ReflectionClass::isFinal() (php-src ext/shmop/shmop.stub.php; #28423)
--FILE--
<?php
echo (new ReflectionClass(Shmop::class))->isFinal() ? "shmop_final_yes\n" : "shmop_final_no\n";
?>
--EXPECT--
shmop_final_yes
