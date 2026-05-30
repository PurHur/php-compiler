--TEST--
language: echo object with __toString invokes magic method (issue #3564, Zend zend_print_variable)
--FILE--
<?php
class T {
    public function __toString(): string
    {
        return 'x';
    }
}
echo new T();
--EXPECT--
x
