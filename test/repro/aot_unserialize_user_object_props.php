<?php
// AOT: unserialize user/stdClass public props (#35107)
class A
{
    public $x = 1;
}
class Z
{
    public $a = 1;
    public $b = 2;
}
class S
{
    public $name = '';
    public $flag = false;
    public $n = 0;
}

$o = unserialize(serialize(new A()));
echo 'x=', $o->x, '|';

$lit = unserialize('O:1:"A":1:{s:1:"x";i:42;}');
echo 'lit=', $lit->x, '|';

$z = unserialize('O:1:"Z":2:{s:1:"a";i:10;s:1:"b";i:20;}');
echo 'a=', $z->a, ',b=', $z->b, '|';

$s = unserialize(serialize((object) ['k' => 7]));
echo 'k=', $s->k, '|';

$m = unserialize('O:1:"S":3:{s:4:"name";s:2:"hi";s:4:"flag";b:1;s:1:"n";i:3;}');
echo 'name=', $m->name, ',flag=', $m->flag ? '1' : '0', ',n=', $m->n, "\n";
