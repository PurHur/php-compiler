--TEST--
Language: backed enum in double-quoted strings — Error (zend_enum.c, #4785)
--FILE--
<?php
enum Color: string { case Red = 'r'; }
$c = Color::Red;
try {
    echo "$c";
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}
echo Color::Red->name, '|', Color::Red->value, "\n";
--EXPECT--
Object of class Color could not be converted to string
Red|r
