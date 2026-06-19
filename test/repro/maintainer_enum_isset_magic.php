<?php
declare(strict_types=1);

enum E: int { case A = 1; }

var_export(isset(E::A->name));
echo "\n";
var_export(isset(E::A->value));
echo "\n";
var_export(empty(E::A->name));
echo "\n";
