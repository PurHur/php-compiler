--TEST--
Language: Closure::bind() accepts enum case as $newThis (#10218, zend_closures.c)
--FILE--
<?php
declare(strict_types=1);

enum E: int {
    case A = 1;
    public function m(): int { return $this->value; }
}

$c = Closure::bind(function (): int { return $this->value; }, E::A, E::class);
echo $c(), "\n";

$c2 = (function (): int { return $this->value; })->bindTo(E::A, E::class);
echo $c2(), "\n";
--EXPECT--
1
1
