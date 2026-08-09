--TEST--
Language: (clone $obj) with [...] rejected like Zend (#10496 superseded by #29187)
--FILE--
<?php
declare(strict_types=1);

class C {
    public int $x = 1;
    public string $y = 'a';
}

$c = new C();
$d = (clone $c) with ['x' => 2, 'y' => 'b'];
var_export([$d->x, $d->y]);
--EXPECT_EXIT--
255
