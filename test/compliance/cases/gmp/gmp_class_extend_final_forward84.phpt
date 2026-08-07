--TEST--
class cannot extend final GMP under PROFILE≥8.4 (php-src ext/gmp/gmp.stub.php; #28135)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
class BadGmp extends GMP {}
echo "EXTENDED_OK\n";
?>
--EXPECT_EXIT--
255
--EXPECTREGEX--
Class BadGmp cannot extend final class GMP
