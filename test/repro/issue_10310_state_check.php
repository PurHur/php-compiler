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
$w2 = clone($w, ['a']);
var_export([$w->a, $w->b, $w2->a, $w2->b]);
echo "\n";
