<?php

$f = new Fiber(function (): void {
    echo "in fiber 1\n";
    $v = Fiber::suspend("s1");
    echo "in fiber 2: ".$v."\n";
});

var_dump($f->isStarted(), $f->isSuspended(), $f->isTerminated());
var_dump($f->start());
var_dump($f->isSuspended());
var_dump($f->resume("r1"));
var_dump($f->isTerminated());

