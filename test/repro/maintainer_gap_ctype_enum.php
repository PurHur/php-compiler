<?php
// Maintainer repro for #8962 — ctype_* enum operands must return false (php-src ext/standard/ctype.c).
enum E: string { case A = 'abc'; case B = '123'; }

var_dump(ctype_alpha(E::A));
var_dump(ctype_digit(E::B));
var_dump(ctype_alnum(E::A));
