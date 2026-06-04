<?php
// Issue #5485 — Fiber::suspend() static call inside fiber callback (zend_fibers.c).
$f = new Fiber(function (): void {
    echo 'start', "\n";
    Fiber::suspend('resume');
});
$f->start();
$f->resume();
