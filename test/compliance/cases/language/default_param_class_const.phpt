--TEST--
Default parameter: global const, self::, and promoted property (#6542)
--FILE--
<?php
const MAX = 10;

function f(int $x = MAX): int {
    return $x;
}

class C {
    public const M = 20;
    public function __construct(public int $x = self::M) {}
}

echo f(), "\n";
$c = new C();
echo $c->x, "\n";
--EXPECT--
10
20
