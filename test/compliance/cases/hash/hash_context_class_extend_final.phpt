--TEST--
class cannot extend final HashContext (php-src ext/hash/hash.stub.php; #28384)
--FILE--
<?php
class BadHashContext extends HashContext {}
echo "EXTENDED_OK\n";
?>
--EXPECT_EXIT--
255
--EXPECTREGEX--
Class BadHashContext cannot extend final class HashContext
