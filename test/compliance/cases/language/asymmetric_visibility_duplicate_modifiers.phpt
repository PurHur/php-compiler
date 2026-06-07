--TEST--
Language: asymmetric visibility — public private(set) duplicate modifiers compile fatal (#7388, zend_compile.c)
--FILE--
<?php
class C {
    public private(set) string $x = 'a';
}
echo 1;
--EXPECT_EXIT--
255
--FILE--
<?php
class C {
    public protected(set) string $x = 'a';
}
echo 1;
--EXPECT_EXIT--
255
--FILE--
<?php
class C {
    public function __construct(
        public private(set) string $name,
    ) {}
}
echo 1;
--EXPECT_EXIT--
255
