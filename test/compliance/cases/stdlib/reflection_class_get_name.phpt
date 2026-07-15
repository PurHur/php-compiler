--TEST--
stdlib ReflectionClass::getName() fully qualified class name (#4465, ext/reflection/php_reflection.c)
--FILE--
<?php
namespace Foo\Bar {
    class Baz {}
}
namespace {
    class GlobalC {}
    echo (new ReflectionClass(GlobalC::class))->getName(), "\n";
    echo (new ReflectionClass(stdClass::class))->getName(), "\n";
    echo (new ReflectionClass('Foo\\Bar\\Baz'))->getName(), "\n";
}
--EXPECT--
GlobalC
stdClass
Foo\Bar\Baz
