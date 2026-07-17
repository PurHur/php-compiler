<?php
declare(strict_types=1);
enum E: int { case A = 1; }
var_export(is_int(E::A));
echo "\n";
var_export(is_object(E::A));
echo "\n";
