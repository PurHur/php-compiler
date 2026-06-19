<?php

declare(strict_types=1);

enum E: int {
    case A = 1;
}

var_dump(E::A |> (fn($x) => $x)());
var_dump(E::A |> (fn($x) => $x->name)());
