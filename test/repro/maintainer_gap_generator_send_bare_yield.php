<?php
declare(strict_types=1);

function g(): Generator {
    $x = yield;
    yield $x * 2;
}

$g = g();
$send = $g->send(3);
echo 'send=', var_export($send, true), "\n";
echo 'current=', var_export($g->current(), true), "\n";

$g2 = g();
$g2->rewind();
$g2->send(3);
echo 'rewind_current=', var_export($g2->current(), true), "\n";
