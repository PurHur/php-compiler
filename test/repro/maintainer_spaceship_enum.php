<?php
declare(strict_types=1);

// Issue #9796 — backed enum <=> must compare backing values (Zend/zend_enum.c).
enum E: int { case A = 1; case B = 2; }

var_dump(E::A <=> E::B);
var_dump(E::A <=> 1);
var_dump(E::A <=> 'x');
