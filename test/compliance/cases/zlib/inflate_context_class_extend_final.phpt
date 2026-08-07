--TEST--
class cannot extend final InflateContext (php-src ext/zlib/zlib.stub.php; #28385)
--FILE--
<?php
class BadInflateContext extends InflateContext {}
echo "EXTENDED_OK\n";
?>
--EXPECT_EXIT--
255
--EXPECTREGEX--
Class BadInflateContext cannot extend final class InflateContext
