--TEST--
AOT: readonly class property write Error is catchable (#26826)
--FILE--
<?php
readonly class Point {
    public function __construct(public int $x, public int $y) {}
}
$p = new Point(1, 2);
echo $p->x + $p->y, "\n";
try {
    $p->x = 3;
} catch (Error $e) {
    echo "err\n";
}
--EXPECT--
3
err
