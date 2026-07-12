<?php

$f = new Fiber(function (): void {
    Fiber::suspend('step1');
    Fiber::suspend('step2');
});
echo $f->start() . "\n";
echo $f->resume() . "\n";
echo $f->resume() . "\n";
