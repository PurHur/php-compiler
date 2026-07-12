--TEST--
Language: Exception::__construct() — non-strict Z_PARAM_STR message coercion (#18189, Zend/zend_exceptions.c)
--FILE--
<?php

class StringableMsg implements Stringable {
    public function __toString(): string
    {
        return 'boom';
    }
}

$e = new Exception(new StringableMsg());
echo 'Stringable:', $e->getMessage(), "\n";

$e = new RuntimeException(123);
echo 'int:', $e->getMessage(), "\n";
?>
--EXPECT--
Stringable:boom
int:123
