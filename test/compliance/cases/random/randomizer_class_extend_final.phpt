--TEST--
class cannot extend final Random\Randomizer (php-src ext/random/random.stub.php; #28387)
--FILE--
<?php
class BadRandomizer extends Random\Randomizer {}
echo "EXTENDED_OK\n";
?>
--EXPECT_EXIT--
255
--EXPECTREGEX--
Class BadRandomizer cannot extend final class Random\\Randomizer
