<?php
/**
 * Repro #28832 — FiberError/ArithmeticError/ArgumentCountError getFile()/getLine() + uncaught headers.
 */
$f = new Fiber(fn() => 1);
$f->start();
try {
    $f->resume();
} catch (FiberError $e) {
    echo 'fiber file=', var_export($e->getFile(), true), ' line=', $e->getLine(), "\n";
}
try {
    echo 1 << -1;
} catch (ArithmeticError $e) {
    echo 'arith file=', basename($e->getFile()), ' line=', $e->getLine(), "\n";
}
try {
    strlen('a', 'b');
} catch (ArgumentCountError $e) {
    echo 'argc file=', basename($e->getFile()), ' line=', $e->getLine(), "\n";
}
try {
    strlen([]);
} catch (TypeError $e) {
    echo 'type file=', basename($e->getFile()), ' line=', $e->getLine(), "\n";
}
