<?php
enum E: int {
    case A = 1;
    public const FOO = 2;
}
var_export([E::FOO, E::A->value]);
echo "\n";
