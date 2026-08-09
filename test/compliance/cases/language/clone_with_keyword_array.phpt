--TEST--
Language: clone $obj with ['prop'] keyword array rejected like Zend (#9995 superseded by #29187)
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
$w2 = clone $w with ['a'];
echo $w2->a, ',', $w2->b, "\n";
--EXPECT_EXIT--
255
