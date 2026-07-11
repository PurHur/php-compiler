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

echo "ok\n";
