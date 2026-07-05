--TEST--
PHP 8.4 asymmetric visibility: constructor-promoted public (private(set)) compiles (#16495, zend_compile.c)
--FILE--
<?php
class User {
    public function __construct(
        public (private(set)) string $name,
    ) {}
}
echo (new User('alice'))->name, "\n";
--EXPECT--
alice
