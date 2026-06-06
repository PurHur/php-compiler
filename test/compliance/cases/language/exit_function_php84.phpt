--TEST--
Language: exit()/die() as PHP 8.4 functions — FCC, named args, strict_types (#6975)
--FILE--
<?php
declare(strict_types=1);

$callable = exit(...);
var_dump($callable instanceof Closure);
$callable(0);

echo "unreachable\n";
--EXPECT--
bool(true)
--EXPECT_EXIT--
0
