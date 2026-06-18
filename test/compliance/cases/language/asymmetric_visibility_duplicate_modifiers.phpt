--TEST--
Language: asymmetric visibility — public private(set) combined read/set compile fatal (#9161, zend_compile.c)
--FILE--
<?php
class C {
    public private(set) string $x = 'a';
}
echo "ok\n";
--EXPECT_EXIT--
255
--FILE--
<?php
class C {
    public protected(set) string $x = 'b';
}
echo "ok\n";
--EXPECT_EXIT--
255
--FILE--
<?php
class C {
    public function __construct(
        public private(set) string $name,
    ) {}
}
echo "ok\n";
--EXPECT_EXIT--
255
