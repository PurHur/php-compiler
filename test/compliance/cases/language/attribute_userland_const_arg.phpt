--TEST--
Language: attribute ctor args fold userland global/ns consts (#26628, zend_compile.c)
--FILE--
<?php
namespace {
    #[Attribute(Attribute::TARGET_ALL | Attribute::IS_REPEATABLE)]
    class Attr {
        public function __construct(public mixed $v) {}
    }
    const G = 4;
    #[Attr(G)]
    #[Attr(PHP_INT_SIZE)]
    function f() {}
    $attrs = (new ReflectionFunction('f'))->getAttributes();
    echo $attrs[0]->newInstance()->v, "\n";
    echo $attrs[1]->newInstance()->v, "\n";
}
namespace N {
    const C = 7;
    #[\Attr(C)]
    function g() {}
    echo (new \ReflectionFunction(__NAMESPACE__ . '\\g'))->getAttributes()[0]->newInstance()->v, "\n";
}
--EXPECTF--
4
%d
7
