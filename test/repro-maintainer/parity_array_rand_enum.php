<?php

enum E: int
{
    case A = 1;
    case B = 2;
    case C = 3;
}

$arr = [E::A, E::B, E::C];
$idx = array_rand($arr);
var_dump($idx);
var_dump($arr[$idx]);
