--TEST--
class cannot extend final AddressInfo (php-src ext/sockets/sockets.stub.php; #28391)
--FILE--
<?php
class BadAddressInfo extends AddressInfo {}
echo "EXTENDED_OK\n";
?>
--EXPECT_EXIT--
255
--EXPECTREGEX--
Class BadAddressInfo cannot extend final class AddressInfo
