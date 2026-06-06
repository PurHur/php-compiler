--TEST--
Language: promoted asymmetric visibility public private(set) — compile-time fatal (#6742, zend_compile.c)
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
