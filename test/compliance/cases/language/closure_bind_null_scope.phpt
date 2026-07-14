--TEST--
Language: Closure::bind($closure, $object, null) — bound $this matches bindTo (#18880, zend_closures.c)
--FILE--
<?php
declare(strict_types=1);

$c = function (): int {
    return $this->x;
};

class X {
    public int $x = 5;
}

echo Closure::bind($c, new X(), null)(), "\n";
echo $c->bindTo(new X(), null)(), "\n";
--EXPECT--
5
5
