--TEST--
Language: variadic parameter before required/default — compile-time fatal (#16721, re-#7411)
--FILE--
<?php
declare(strict_types=1);

function g(mixed ...$rest, int $b = 1): void
{
    echo $b, "\n";
}

g(b: 2);
--EXPECT_EXIT--
255
--EXPECTF--
parseAndCompile failure: target=%s: Only the last parameter can be variadic
