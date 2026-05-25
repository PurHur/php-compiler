--TEST--
readonly class: JIT rejects property write after construction (issue #1360)
--FILE--
<?php
readonly class Box {
    public int $v;
    public function __construct() {
        $this->v = 1;
    }
}
$o = new Box();
try {
    $o->v = 2;
    echo "mutated\n";
} catch (\Throwable $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
Cannot modify readonly property Box::$v
