--TEST--
Language: __isset(): int — compile-time fatal (#26463)
--FILE--
<?php
class Ci { public function __isset(string $n): int { return 1; } }
--EXPECTF--
Fatal error: Ci::__isset(): Return type must be bool when declared in %s on line %d
--EXPECT_EXIT--
255
