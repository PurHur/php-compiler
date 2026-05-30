--TEST--
language: Closure::bindTo() rebinds $this (issue #3266)
--FILE--
<?php
class D { public int $x = 42; }
class Maker {
    public function make(): Closure {
        return function () { return $this->x; };
    }
}
$fn = (new Maker())->make();
$bound = $fn->bindTo(new D(), D::class);
echo $bound(), "\n";
--EXPECT--
42
