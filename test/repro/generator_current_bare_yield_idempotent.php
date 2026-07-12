<?php
declare(strict_types=1);

function g(): Generator {
    $x = yield;
    yield $x * 2;
}

$g = g();
$g->rewind();
$g->send(3);
$a = $g->current();
$b = $g->current();
echo "a={$a} b={$b}\n";
