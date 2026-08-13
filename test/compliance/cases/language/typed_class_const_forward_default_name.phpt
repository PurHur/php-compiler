--TEST--
Language: typed class constants parse and evaluate under PHP_COMPILER_PROFILE=8.3 (#30176 / #30857, Zend/zend_compile.c)
--ENV--
PHP_COMPILER_PROFILE=8.3
--FILE--
<?php
class Config {
    const string NAME = "app";
    const int VERSION = 1;
}
echo Config::NAME . " v" . Config::VERSION . "\n";
--EXPECT--
app v1
