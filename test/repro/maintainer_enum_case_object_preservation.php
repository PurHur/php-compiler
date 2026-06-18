<?php
enum E: int { case A = 1; case B = 2; }

function takesEnum(E $e): void {
    echo $e->name, "\n";
}

takesEnum(E::A);

var_export(E::A);
echo "\n";
var_export([E::A, E::B]);
echo "\n";
