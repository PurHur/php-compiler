<?php

enum E: int
{
    case A = 1;
}

$c = E::A;
echo 'intval: ', @intval($c), "\n";
echo 'floatval: ', @floatval($c), "\n";
var_export(error_get_last());
