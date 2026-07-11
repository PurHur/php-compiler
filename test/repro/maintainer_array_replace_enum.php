<?php
enum E: int { case A = 1; case B = 2; }
var_export(array_replace([E::A], [1 => E::B]));
echo PHP_EOL;
var_export(array_replace_recursive([E::A], [1 => E::B]));
echo PHP_EOL;
