<?php
enum E: int { case A = 1; case B = 2; }
$a = [E::A, E::B];
var_dump(array_pad($a, 4, E::A));
var_dump(array_chunk($a, 1));
