--TEST--
Language: typed property weak-mode int coercion (#12347, Zend/zend_types.c)
--FILE--
<?php
class IntProp
{
    public int $p;
}

$c = new IntProp();
$c->p = 1.5;
echo 'float:'.$c->p."\n";

$c->p = '42.0';
echo 'str:'.$c->p."\n";
--EXPECT--
float:1
str:42
