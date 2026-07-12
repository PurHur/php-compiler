<?php
function g(): Generator {
    $x = yield;
    yield $x * 2;
}
$g = g();
$g->rewind();
$g->send(3);
echo $g->current(), "\n";
echo $g->current(), "\n";
