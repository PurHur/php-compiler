--TEST--
AOT: var_dump typed int/float/bool instance property boxes scalar (#33018)
--FILE--
<?php
class IntProp
{
    public int $n = 2;
}
class FloatProp
{
    public float $f = 1.5;
}
class BoolProp
{
    public bool $b = true;
}

$i = new IntProp();
var_dump($i->n);
--$i->n;
var_dump($i->n);

$f = new FloatProp();
var_dump($f->f);

$b = new BoolProp();
var_dump($b->b);
--EXPECT--
int(2)
int(1)
float(1.5)
bool(true)
