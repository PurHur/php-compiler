<?php
declare(strict_types=1);

function g(): Generator {
    $x = yield;
    echo "got={$x}\n";
}
$gen = g();
$gen->current();
$gen->send(42);
