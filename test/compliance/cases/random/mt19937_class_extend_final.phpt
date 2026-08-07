--TEST--
class cannot extend final Random\Engine\Mt19937 (php-src ext/random/random.stub.php; #28387)
--FILE--
<?php
class BadMt extends Random\Engine\Mt19937 {}
echo "EXTENDED_OK\n";
?>
--EXPECT_EXIT--
255
--EXPECTREGEX--
Class BadMt cannot extend final class Random\\Engine\\Mt19937
