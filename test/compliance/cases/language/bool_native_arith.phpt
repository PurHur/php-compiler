--TEST--
Language: native bool ⊙ int/float arithmetic (#32337, Zend/zend_operators.c convert_scalar_to_number)
--FILE--
<?php
function arith(bool $t, bool $f, int $n, float $d): void
{
    var_dump($t + $n);
    var_dump($n + $t);
    var_dump($f * $n);
    var_dump($t - 1);
    var_dump($t / 2);
    var_dump($t + $d);
    var_dump($d + $f);
    var_dump($t + $t);
    var_dump($t <=> 0);
    echo ($t > 0) ? "gt\n" : "ngt\n";
}
arith(true, false, 5, 1.5);
?>
--EXPECT--
int(6)
int(6)
int(0)
int(0)
float(0.5)
float(2.5)
float(1.5)
int(2)
int(1)
gt
