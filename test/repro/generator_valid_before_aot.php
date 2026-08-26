<?php
// Repro for #34972 — Generator::valid() true while on first yield.
function g()
{
    yield 1;
}
$g = g();
echo $g->current(), '|';
echo $g->valid() ? '1' : '0', '|';
echo "\n";
