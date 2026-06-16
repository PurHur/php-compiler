<?php
declare(strict_types=1);

enum E: int {
    case A = 1;
}
var_export(get_mangled_object_vars(E::A));
echo "\n";
