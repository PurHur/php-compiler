--TEST--
class cannot extend final FTP\Connection (php-src ext/ftp/ftp.stub.php; #28403)
--FILE--
<?php
class BadFtpConn extends FTP\Connection {}
echo "EXTENDED_OK\n";
?>
--EXPECT_EXIT--
255
--EXPECTREGEX--
Class BadFtpConn cannot extend final class FTP\\Connection
