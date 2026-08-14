--TEST--
Language: try/catch/else rejected on php-src-strict (#31159, Zend/zend_language_parser.y)
--FILE--
<?php
try {
    echo "try\n";
} catch (Throwable) {
} else {
    echo "else\n";
}
--EXPECT_EXIT--
255
--EXPECTF--
PHP Parse error:  syntax error, unexpected token "else" in %s on line %d
