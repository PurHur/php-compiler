--TEST--
Language: unqualified builtin calls in namespace resolve to global (issue #10534, Zend/zend_compile.c)
--FILE--
<?php
namespace N;

const C = 1;

var_export(C);
echo "\n";
echo count([1, 2, 3]), "\n";
echo strlen('hi'), "\n";
echo \count([1, 2, 3]), "\n";
--EXPECT--
1
3
2
3
