--TEST--
Language: asymmetric visibility — public private(set) combined read/set compiles (#7460, zend_compile.c)
--FILE--
<?php
class C {
    public private(set) string $x = 'a';
}
echo (new C())->x, "\n";
--EXPECT--
a
--FILE--
<?php
class C {
    public protected(set) string $x = 'b';
}
echo (new C())->x, "\n";
--EXPECT--
b
--FILE--
<?php
class C {
    public function __construct(
        public private(set) string $name,
    ) {}
}
echo (new C('c'))->name, "\n";
--EXPECT--
c
