--TEST--
Language: user function in namespace shadows global for unqualified calls (#10534, Zend/zend_compile.c)
--FILE--
<?php
namespace N {
    function count(array $a): int
    {
        return 99;
    }
    echo count([1, 2, 3]), "\n";
}
--EXPECT--
99
