--TEST--
Language: clone($obj, ['prop']) functional syntax (PHP 8.4, #9743)
--FILE--
<?php
class W {
    public int $a = 1;
    public readonly int $b;

    public function __construct() {
        $this->b = 2;
    }
}

$w = new W();
$w2 = clone($w, ['a']);
echo $w2->a, ',', $w2->b, "\n";
--EXPECT--
1,2
