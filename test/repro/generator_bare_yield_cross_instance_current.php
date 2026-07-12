<?php
declare(strict_types=1);

function g(): Generator {
    $x = yield;
    yield $x * 2;
}

$g = g();
$g->send(3);
echo 'first_current=', var_export($g->current(), true), "\n";

$g2 = g();
$g2->rewind();
$g2->send(3);
echo 'second_current=', var_export($g2->current(), true), "\n";
