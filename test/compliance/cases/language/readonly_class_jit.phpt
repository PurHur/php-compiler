--TEST--
readonly class: JIT rejects property write after construction (issue #1360)
--FILE--
<?php
readonly class Box {
    public function __construct(public int $v) {}
}
$o = new Box(1);
try {
    $o->v = 2;
    echo "mutated\n";
} catch (\Throwable $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
Cannot modify readonly property Box::$v
