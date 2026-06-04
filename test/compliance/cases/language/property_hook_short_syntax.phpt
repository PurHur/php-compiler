--TEST--
Property hook short syntax get => lowers and runs (issue #5404, Zend zend_compile.c PHP 8.4)
--FILE--
<?php
class C {
    public int $p {
        get => 1;
    }
}
echo (new C)->p, "\n";
--EXPECT--
1
