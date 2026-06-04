<?php

enum E: int
{
    case A = 5;
}

$c = E::A;
echo @intval($c), "\n";
echo @floatval($c), "\n";
