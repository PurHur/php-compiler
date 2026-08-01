--TEST--
Language: attribute ctor static::class compile-fatal (#26627, zend_compile.c)
--FILE--
<?php
#[Attribute]
class Attr {
    public function __construct(public string $x) {}
}
class A {
    #[Attr(static::class)]
    function f() {}
}
--EXPECTF--
parseAndCompile failure: target=%s: static::class cannot be used for compile-time class name resolution
