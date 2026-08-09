--TEST--
class cannot extend final Shmop (php-src ext/shmop/shmop.stub.php; #28423)
--FILE--
<?php
class BadShmop extends Shmop {}
echo "EXTENDED_OK\n";
?>
--EXPECT_EXIT--
255
--EXPECTREGEX--
Class BadShmop cannot extend final class Shmop
