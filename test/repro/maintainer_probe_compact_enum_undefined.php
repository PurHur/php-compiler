<?php
declare(strict_types=1);

enum E: int {
    case A = 1;
}

var_export(compact('e'));
$e = E::A;
var_export(compact('e'));
echo "\n";
