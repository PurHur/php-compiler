<?php
declare(strict_types=1);

// #34389 — AOT mb_ereg_replace()/mb_eregi_replace() with runtime strings (leftover #33765/#33656).
$p = 'a';
$r = 'x';
$s = 'aAa';
echo mb_ereg_replace($p, $r, $s), "\n";
echo mb_eregi_replace($p, $r, $s), "\n";
$world = 'World';
$earth = 'Earth';
$hello = 'Hello World';
echo mb_ereg_replace($world, $earth, $hello), "\n";
$no = 'nomatch';
$x = 'X';
$abc = 'abc';
echo mb_ereg_replace($no, $x, $abc), "\n";
