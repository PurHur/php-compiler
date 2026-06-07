<?php

enum E: int
{
    case A = 1;
}

$a = E::A;
var_export(compact('a'));
echo "\n";
