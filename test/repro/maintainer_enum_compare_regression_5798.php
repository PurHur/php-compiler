<?php
/**
 * Maintainer repro for #5798 / regression #5832 — enum case must not === / == its backing scalar.
 *
 * Zend: E::A === 1 and E::A == 1 are both false.
 */
enum E: int
{
    case A = 1;
}

echo 'int-ident:', var_export(E::A === 1, true), "\n";
echo 'int-equal:', var_export(E::A == 1, true), "\n";
echo 'int-ident-rev:', var_export(1 === E::A, true), "\n";
echo 'int-equal-rev:', var_export(1 == E::A, true), "\n";

enum S: string
{
    case B = 'x';
}

echo 'str-ident:', var_export(S::B === 'x', true), "\n";
echo 'str-equal:', var_export(S::B == 'x', true), "\n";
