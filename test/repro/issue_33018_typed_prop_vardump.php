<?php
// #33018 — var_dump typed native instance props must box via loaded scalar, not int64*
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
