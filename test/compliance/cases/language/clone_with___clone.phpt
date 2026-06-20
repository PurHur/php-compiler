--TEST--
Language: clone with property list + __clone() — with overrides after __clone (#10165, zend_cloners.c)
--FILE--
<?php
declare(strict_types=1);

class C {
    public int $x = 1;

    public function __clone(): void {
        $this->x = 99;
    }
}

$c = new C();
$d = clone $c with ['x' => 2];
var_export([$c->x, $d->x]);
echo "\n";
--EXPECT--
array (
  0 => 1,
  1 => 2,
)
