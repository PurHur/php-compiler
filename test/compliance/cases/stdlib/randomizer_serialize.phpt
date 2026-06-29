--TEST--
stdlib Random\Randomizer __serialize round-trip preserves engine sequence (#13476, ext/random/randomizer.c)
--FILE--
<?php
use Random\Engine\Mt19937;
use Random\Randomizer;

$r = new Randomizer(new Mt19937(42));
$r->getInt(1, 100);
$payload = serialize($r);
$r2 = unserialize($payload);
$a = $r->getInt(1, 100);
$b = $r2->getInt(1, 100);
echo ($a === $b && is_string($payload)) ? "ok\n" : "fail\n";
--EXPECT--
ok
