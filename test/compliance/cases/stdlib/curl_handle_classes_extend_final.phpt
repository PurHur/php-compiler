--TEST--
class cannot extend final CurlHandle (php-src ext/curl/curl.stub.php; #28371)
--ENV--
PHP_COMPILER_ENABLE_CURL=1
--FILE--
<?php
class BadCurlHandle extends CurlHandle {}
echo "EXTENDED_OK\n";
?>
--EXPECT_EXIT--
255
--EXPECTREGEX--
Class BadCurlHandle cannot extend final class CurlHandle
