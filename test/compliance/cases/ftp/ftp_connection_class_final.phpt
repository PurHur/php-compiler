--TEST--
FTP\Connection ReflectionClass::isFinal() (php-src ext/ftp/ftp.stub.php; #28403)
--FILE--
<?php
echo (new ReflectionClass(FTP\Connection::class))->isFinal() ? "ftp_connection_final_yes\n" : "ftp_connection_final_no\n";
?>
--EXPECT--
ftp_connection_final_yes
