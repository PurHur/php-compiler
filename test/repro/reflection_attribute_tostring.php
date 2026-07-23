<?php
// #22420 — ReflectionAttribute::__toString matches Zend (ext/reflection/php_reflection.c).
namespace NS {
    #[\Attribute]
    class Attr
    {
    }
}
namespace {
    #[Attribute]
    class A
    {
    }

    #[A]
    #[NS\Attr]
    function foo()
    {
    }

    $attrs = (new ReflectionFunction('foo'))->getAttributes();
    echo (string) $attrs[0];
    echo (string) $attrs[1];
}
