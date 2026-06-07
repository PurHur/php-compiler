--TEST--
First-class callable on inline new instance (issue #6725, #4957)
--FILE--
<?php
declare(strict_types=1);

class Box {
    public function add(int $a, int $b): int {
        return $a + $b;
    }
}

$f = (new Box())->add(...);
echo $f(1, 2), "\n";
--EXPECT--
3
