--TEST--
Language: promoted asymmetric visibility public private(set) compiles (#7460, zend_compile.c)
--FILE--
<?php
class C {
    public function __construct(
        public private(set) string $name,
    ) {}
}
echo (new C('ok'))->name, "\n";
--EXPECT--
ok
