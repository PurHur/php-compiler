--TEST--
Socket / AddressInfo ReflectionClass::isFinal() (php-src ext/sockets/sockets.stub.php; #28391)
--FILE--
<?php
echo (new ReflectionClass(Socket::class))->isFinal() ? "socket_final_yes\n" : "socket_final_no\n";
echo (new ReflectionClass(AddressInfo::class))->isFinal() ? "addressinfo_final_yes\n" : "addressinfo_final_no\n";
?>
--EXPECT--
socket_final_yes
addressinfo_final_yes
