--TEST--
class cannot extend final Socket (php-src ext/sockets/sockets.stub.php; #28391)
--FILE--
<?php
class BadSocket extends Socket {}
echo "EXTENDED_OK\n";
?>
--EXPECT_EXIT--
255
--EXPECTREGEX--
Class BadSocket cannot extend final class Socket
