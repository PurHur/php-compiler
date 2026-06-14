<?php
enum E: int { case A = 1; case B = 2; }
$found = array_find([E::A, E::B], fn ($v) => $v === E::B);
var_dump($found);
