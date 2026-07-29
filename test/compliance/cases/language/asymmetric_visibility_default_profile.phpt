--TEST--
Language: public private(set) compiles on default 8.4.0-dev profile (#24720, Zend/zend_language_parser.y)
--SKIPIF--
<?php if (PHP_VERSION_ID < 80400) die('skip host PHP < 8.4 — PHPT native runner cannot parse asymmetric visibility'); ?>
--FILE--
<?php
class C {
    public private(set) string $name = 'Alice';
}
$c = new C();
echo $c->name, "\n";
try {
    $c->name = 'Bob';
} catch (Error $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
Alice
Error: Cannot modify private(set) property C::$name from global scope
