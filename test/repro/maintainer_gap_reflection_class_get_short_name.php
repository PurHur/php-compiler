<?php
namespace Foo\Bar {
    class Baz {}
}
namespace {
    // Issue #15274 — ReflectionClass::getShortName() returns unqualified class name.
    echo (new ReflectionClass(stdClass::class))->getShortName(), "\n";
    echo (new ReflectionClass('Foo\\Bar\\Baz'))->getShortName(), "\n";
}
