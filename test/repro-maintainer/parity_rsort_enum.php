<?php

enum E: int
{
    case A = 1;
    case B = 2;
}

$a = [E::B, E::A];
rsort($a);
var_export($a);
echo PHP_EOL;

$b = ['k' => E::B, 'a' => E::A];
arsort($b);
var_export($b);
echo PHP_EOL;
