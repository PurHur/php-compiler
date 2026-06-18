<?php
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
