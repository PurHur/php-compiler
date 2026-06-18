<?php
declare(strict_types=1);
enum E: int { case A = 1; case B = 2; }
var_dump(E::A <=> E::B);
var_dump(null <=> 1);
var_dump('a' <=> 'b');
