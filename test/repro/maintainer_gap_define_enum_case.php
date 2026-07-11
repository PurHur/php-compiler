<?php

declare(strict_types=1);

enum E: int {
    case A = 1;
    case B = 2;
}

define('EC', E::A);
var_export(EC);
