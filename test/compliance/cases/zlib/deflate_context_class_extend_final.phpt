--TEST--
class cannot extend final DeflateContext (php-src ext/zlib/zlib.stub.php; #28385)
--FILE--
<?php
class BadDeflateContext extends DeflateContext {}
echo "EXTENDED_OK\n";
?>
--EXPECT_EXIT--
255
--EXPECTREGEX--
Class BadDeflateContext cannot extend final class DeflateContext
