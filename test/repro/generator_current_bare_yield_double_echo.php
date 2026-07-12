<?php
declare(strict_types=1);

function g(): Generator {
    $x = yield;
    yield $x * 2;
}

$g = g();
$g->rewind();
$g->send(3);
echo var_export($g->current(), true), "\n";
echo var_export($g->current(), true), "\n";
