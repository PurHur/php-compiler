<?php
declare(strict_types=1);

function g(): Generator {
    $x = yield;
    yield $x * 2;
}

$first = g();
$first->send(3);
echo 'first_current=', var_export($first->current(), true), "\n";

$second = g();
$second->rewind();
$second->send(3);
echo 'second_current=', var_export($second->current(), true), "\n";
