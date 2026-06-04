--TEST--
language: clone readonly class with promoted int under strict_types (issue #5701)
--FILE--
<?php
declare(strict_types=1);

readonly class R {
    public function __construct(public int $x = 1) {}
}

$r = new R(2);
$c = clone $r;
echo $c->x;
--EXPECT--
2
