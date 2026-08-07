--TEST--
class cannot extend final CurlMultiHandle (php-src ext/curl/curl.stub.php; #28371)
--ENV--
PHP_COMPILER_ENABLE_CURL=1
--FILE--
<?php
class BadCurlMultiHandle extends CurlMultiHandle {}
echo "EXTENDED_OK\n";
?>
--EXPECT_EXIT--
255
--EXPECTREGEX--
Class BadCurlMultiHandle cannot extend final class CurlMultiHandle
