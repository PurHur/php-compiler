--TEST--
PHP 8.4 asymmetric visibility: constructor-promoted public (private(set)) rejected (#16436, zend_compile.c)
--FILE--
<?php
class User {
    public function __construct(
        public (private(set)) string $name,
    ) {}
}
echo "should not run\n";
--EXPECT_EXIT--
255
