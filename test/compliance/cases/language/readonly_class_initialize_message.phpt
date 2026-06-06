--TEST--
Language: readonly class exterior init — initialize message (issue #5463)
--FILE--
<?php
readonly class R {
    public int $x;
}
$r = new R();
try {
    $r->x = 1;
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}

class C {
    public readonly int $y;
}
$c = new C();
try {
    $c->y = 1;
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}

readonly class RC {
    public function __construct(public string $x = 'init') {}
}
$r2 = new RC();
try {
    $r2->x = 'nope';
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
Cannot initialize readonly property R::$x from global scope
Cannot initialize readonly property C::$y from global scope
Cannot modify readonly property RC::$x
