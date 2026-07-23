--TEST--
Stdlib: ReflectionAttribute::__toString prints Attribute [ Name ] (#22420, ext/reflection/php_reflection.c)
--FILE--
<?php
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
--EXPECT--
Attribute [ A ]
Attribute [ NS\Attr ]
