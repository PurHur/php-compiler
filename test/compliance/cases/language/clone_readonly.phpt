--TEST--
language: clone __clone cannot assign readonly property (issue #4245)
--FILE--
<?php
class Point {
    public function __construct(public readonly int $x) {}
    public function __clone(): void {
        $this->x = $this->x + 1;
    }
}
$p = new Point(1);
$c = clone $p;
echo $c->x, "\n";
--EXPECT_EXIT--
255
