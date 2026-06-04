<?php
/**
 * Maintainer repro for #5798 — enum case must not === / == its backing scalar.
 *
 * Zend: E::A === 1 and E::A == 1 are both false.
 */
enum E: int
{
    case A = 1;
}

var_export(E::A === 1);
echo "\n";
var_export(E::A == 1);
echo "\n";
var_export(1 === E::A);
echo "\n";
var_export(1 == E::A);
echo "\n";

enum S: string
{
    case B = 'x';
}

var_export(S::B === 'x');
echo "\n";
var_export(S::B == 'x');
echo "\n";
