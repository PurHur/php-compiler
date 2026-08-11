--TEST--
Language: typed class constants parse and evaluate on default profile (#30176, Zend/zend_compile.c)
--FILE--
<?php
class Config {
    const string NAME = "app";
    const int VERSION = 1;
}
echo Config::NAME . " v" . Config::VERSION . "\n";
--EXPECT--
app v1
