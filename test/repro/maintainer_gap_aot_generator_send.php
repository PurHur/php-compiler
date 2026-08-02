<?php
// Repro for #26819 — Generator::send under AOT (php-src-strict).
// Avoid print_r/var_dump (#23540). Match Zend: current then send.
function g()
{
    $a = yield 1;
    yield $a;
}
$g = g();
echo $g->current(), '|';
echo $g->send('x'), '|';
echo "\n";
