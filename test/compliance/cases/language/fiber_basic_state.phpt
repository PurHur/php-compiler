--TEST--
Fiber basic state transitions (Zend/zend_fibers.c parity, #4481)
--FILE--
<?php
$f = new Fiber(function (): void {
    echo "in fiber 1\n";
    $v = Fiber::suspend("s1");
    echo "in fiber 2: ".$v."\n";
});

var_dump($f->isStarted(), $f->isSuspended(), $f->isRunning(), $f->isTerminated());
var_dump($f->start());
var_dump($f->isStarted(), $f->isSuspended(), $f->isRunning(), $f->isTerminated());
var_dump($f->resume("r1"));
var_dump($f->isStarted(), $f->isSuspended(), $f->isRunning(), $f->isTerminated());
--EXPECT--
bool(false)
bool(false)
bool(false)
bool(false)
in fiber 1
string(2) "s1"
bool(true)
bool(true)
bool(false)
bool(false)
in fiber 2: r1
NULL
bool(true)
bool(false)
bool(false)
bool(true)

