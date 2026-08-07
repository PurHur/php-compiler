--TEST--
class cannot extend final CurlShareHandle (php-src ext/curl/curl.stub.php; #28371)
--ENV--
PHP_COMPILER_ENABLE_CURL=1
--FILE--
<?php
class BadCurlShareHandle extends CurlShareHandle {}
echo "EXTENDED_OK\n";
?>
--EXPECT_EXIT--
255
--EXPECTREGEX--
Class BadCurlShareHandle cannot extend final class CurlShareHandle
