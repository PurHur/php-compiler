--TEST--
Language: clone-with property list reinitializes defaults (#10310, Zend/zend_clones.c)
--FILE--
<?php
declare(strict_types=1);

class W {
    public int $a = 1;
    public readonly int $b;

    public function __construct() {
        $this->b = 2;
    }
}

$w = new W();
$w->a = 99;
$w2 = clone($w, ['a']);
var_export([$w->a, $w->b, $w2->a, $w2->b]);
--EXPECT--
array (
  0 => 99,
  1 => 2,
  2 => 1,
  3 => 2,
)
