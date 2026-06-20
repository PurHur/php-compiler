<?php
enum E: int { case A = 1; case B = 2; }
[$a, $b] = [E::A, E::B];
var_export([$a, $b]);
echo "\n";
