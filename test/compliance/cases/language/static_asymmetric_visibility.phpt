--TEST--
PHP 8.4 static asymmetric visibility: bare private(set) static rejected (#15446, Zend/zend_language_scanner.l)
--FILE--
<?php
class C {
    private(set) static string $name = 'x';
}
echo C::$name, "\n";
--EXPECT_EXIT--
255
--EXPECTF--
Fatal error: syntax error, unexpected token ")", expecting variable in %s on line %d
