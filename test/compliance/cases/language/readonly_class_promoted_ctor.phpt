--TEST--
readonly class: constructor-promoted property constructs (issue #4758)
--FILE--
<?php
readonly class R {
    public function __construct(public int $x) {}
}
echo (new R(5))->x, "\n";
try {
    $r = new R(5);
    $r->x = 9;
    echo "mutated\n";
} catch (\Throwable $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
5
Cannot modify readonly property R::$x
