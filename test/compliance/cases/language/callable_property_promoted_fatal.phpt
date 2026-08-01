--TEST--
Language: promoted callable property type — compile fatal (#26516, zend_compile.c)
--FILE--
<?php
class C {
    public function __construct(public callable $c) {}
}
echo "ok\n";
--EXPECTF--
Fatal error: Property C::$c cannot have type callable in %s on line %d
