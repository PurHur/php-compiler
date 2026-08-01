--TEST--
language: non-public __invoke warns; $obj() dispatches, $obj->__invoke() fatals (issue #26438)
--FILE--
<?php
error_reporting(E_ALL);
class Priv {
    private function __invoke(): mixed { return 42; }
}
class Prot {
    protected function __invoke(): mixed { return 7; }
}

$p = new Priv;
echo 'call:', $p(), "\n";
try {
    echo $p->__invoke(), "\n";
} catch (Error $e) {
    echo 'method:', $e->getMessage(), "\n";
}
echo 'prot:', (new Prot)(), "\n";
--EXPECTF--
Warning: The magic method Priv::__invoke() must have public visibility in %s on line %d
Warning: The magic method Prot::__invoke() must have public visibility in %s on line %d
call:42
method:Call to private method Priv::__invoke() from global scope
prot:7
