<?php
$seed = "\x01\x02\x03\x04\x05\x06\x07\x08\x09\x0a\x0b\x0c\x0d\x0e\x0f\x10\x11\x12\x13\x14\x15\x16\x17\x18\x19\x1a\x1b\x1c\x1d\x1e\x1f\x20";
$e = new Random\Engine\Xoshiro256StarStar($seed);
echo bin2hex($e->generate()), "\n";
echo bin2hex($e->generate()), "\n";
$r = new Random\Randomizer(new Random\Engine\Xoshiro256StarStar($seed));
echo "nextInt=", $r->nextInt(), "\n";
$r2 = new Random\Randomizer(new Random\Engine\Xoshiro256StarStar($seed));
echo "getInt=", $r2->getInt(0, 10), "\n";
