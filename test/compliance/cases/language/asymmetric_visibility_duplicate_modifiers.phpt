--TEST--
Language: asymmetric visibility — duplicate public public(set) compile fatal (#6774, zend_compile.c)
--FILE--
<?php
class C {
    public public(set) string $x = 'a';
}
echo "ok\n";
--EXPECT_EXIT--
255
--FILE--
<?php
class C {
    public function __construct(
        public public(set) string $name,
    ) {}
}
echo "ok\n";
--EXPECT_EXIT--
255
