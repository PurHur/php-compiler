--TEST--
stdlib ReflectionClass::getShortName() unqualified name (#15274, ext/reflection/php_reflection.c)
--FILE--
<?php
namespace Foo\Bar {
    class Baz {}
}
namespace {
    echo (new ReflectionClass(stdClass::class))->getShortName(), "\n";
    echo (new ReflectionClass('Foo\\Bar\\Baz'))->getShortName(), "\n";
}
--EXPECT--
stdClass
Baz
