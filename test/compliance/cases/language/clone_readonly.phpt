--TEST--
language: clone __clone may reinit readonly property once (issue #4245, PHP 8.3+ #15365)
--ENV--
PHP_COMPILER_PROFILE=8.3
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
--EXPECT--
2
