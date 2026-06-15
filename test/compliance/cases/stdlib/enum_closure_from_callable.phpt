--TEST--
stdlib: Closure::fromCallable([enumCase, method]) invokes case method (#5721, zend_closures.c)
--FILE--
<?php
declare(strict_types=1);

enum E {
    case A;
    public function m(): void { echo "ok\n"; }
}

$c = Closure::fromCallable([E::A, 'm']);
$c();
--EXPECT--
ok
