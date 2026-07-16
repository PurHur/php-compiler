--TEST--
AOT: Random\Randomizer::getBytesFromString() seeded Mt19937 (#19574, PHP 8.3+)
--FILE--
<?php
$r = new Random\Randomizer(new Random\Engine\Mt19937(1));
echo bin2hex($r->getBytesFromString('abcdef', 8)), "\n";
--EXPECT--
6665626364616561
--ENV--
PHP_COMPILER_PROFILE=8.3
