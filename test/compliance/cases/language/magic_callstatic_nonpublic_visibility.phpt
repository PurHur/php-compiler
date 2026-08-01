--TEST--
language: non-public __callStatic warns and still dispatches (issue #26437)
--FILE--
<?php
error_reporting(E_ALL);
class Priv {
    private static function __callStatic($n, $a) {
        return "priv:$n:" . count($a);
    }
}
class Prot {
    protected static function __callStatic($n, $a) {
        return "prot:$n:" . count($a);
    }
}
echo Priv::foo('a'), "\n";
echo Prot::bar('b', 'c'), "\n";
--EXPECTF--
Warning: The magic method Priv::__callStatic() must have public visibility in %s on line %d
Warning: The magic method Prot::__callStatic() must have public visibility in %s on line %d
priv:foo:1
prot:bar:2
