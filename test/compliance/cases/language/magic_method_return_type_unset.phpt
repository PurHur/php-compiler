--TEST--
Language: __unset(): int — compile-time fatal (#26432)
--FILE--
<?php
class Cu { public function __unset(string $n): int { return 1; } }
--EXPECTF--
Fatal error: Cu::__unset(): Return type must be void when declared in %s on line %d
--EXPECT_EXIT--
255
