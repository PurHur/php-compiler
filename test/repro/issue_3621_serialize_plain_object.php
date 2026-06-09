<?php

declare(strict_types=1);

echo serialize(new stdClass()), "\n";
$o = new stdClass();
$o->x = 1;
$o->msg = 'hi';
echo serialize($o), "\n";

class C
{
    public int $x = 1;

    public string $y = 'z';
}

$c = new C();
echo serialize($c), "\n";
$round = unserialize(serialize($c));
var_export($round instanceof C);
echo "\n";
var_export($round->x);
echo "\n";
var_export($round->y);
echo "\n";
