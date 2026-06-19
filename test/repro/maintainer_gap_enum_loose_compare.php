<?php
declare(strict_types=1);

// Issue #9727 — enum case loose == / != with backing scalar (re-#9660/#9583, zend_operators.c)
enum E: int { case A = 1; }
enum S: string { case B = 'b'; }

var_export(E::A == 1);
echo "\n";
var_export(E::A != 1);
echo "\n";
var_export(S::B == 'b');
echo "\n";
var_export(S::B === 'b');
echo "\n";
