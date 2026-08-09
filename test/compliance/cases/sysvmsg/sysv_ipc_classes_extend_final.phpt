--TEST--
class cannot extend final Sysv IPC handles (php-src stubs; #28422)
--FILE--
<?php
class BadMsg extends SysvMessageQueue {}
echo "EXTENDED_OK\n";
?>
--EXPECT_EXIT--
255
--EXPECTREGEX--
Class BadMsg cannot extend final class SysvMessageQueue
