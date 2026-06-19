<?php

declare(strict_types=1);

$f = new Fiber(function () {
    Fiber::suspend(1);
    return 99;
});
$f->start();
var_dump($f->resume());
var_dump($f->getReturn());

$g = new Fiber(function () {
    $v = Fiber::suspend();
    var_dump($v);
    return 1;
});
$g->start();
var_dump($g->resume(77));
var_dump($g->getReturn());
