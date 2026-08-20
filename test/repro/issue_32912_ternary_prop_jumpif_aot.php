<?php
/**
 * #32912 — AOT ternary with property condition must compile (peer #32880).
 * php-src: Zend/zend_compile.c ternary / JMPZ
 */
class C
{
    public $n = 'x';
}
$o = new C();
echo ($o->n ? $o->n : 'z');
echo '|';
echo ($o ? $o->n : 'n');
echo '|';
$n = 'x';
echo ($n ? $n : 'z');
echo PHP_EOL;
