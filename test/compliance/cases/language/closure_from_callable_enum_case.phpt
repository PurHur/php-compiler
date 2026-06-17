--TEST--
language: Closure::fromCallable([enumCase, method]) backed enum returns value (#9250, zend_closures.c)
--FILE--
<?php
declare(strict_types=1);

enum E: int {
    case A = 1;
    public function f(): int { return 42; }
}

$c = Closure::fromCallable([E::A, 'f']);
echo $c(), "\n";
--EXPECT--
42
