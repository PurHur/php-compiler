--TEST--
language: Closure::bindTo() private property access (JIT, #4192)
--FILE--
<?php
class C {
    private int $x = 1;
    public function get(): int { return $this->x; }
}

$c = new C();
$fn = function (): int { return $this->x; };
$bound = $fn->bindTo($c, C::class);
echo $bound(), "\n";
--EXPECT--
1
