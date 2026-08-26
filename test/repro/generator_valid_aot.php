<?php
// Repro for #34972 — Generator::valid() under AOT (php-src-strict).
// Avoid print_r/var_dump (#23540). Match Zend: current, next, valid.
function g()
{
    yield 1;
    yield 2;
}
$g = g();
echo $g->current(), '|';
$g->next();
echo $g->current(), '|';
$g->next();
echo $g->valid() ? '1' : '0', '|';
echo "\n";
