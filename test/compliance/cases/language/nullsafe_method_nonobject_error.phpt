--TEST--
Language: nullsafe ?-> method on non-null non-object throws Error (#26364, re-#18026, Zend/zend_vm_def.h)
--FILE--
<?php
error_reporting(E_ALL);
foreach ([null, false, 0, '', [], true, 1] as $i => $x) {
    try {
        $y = $x?->foo();
        echo $i, ':', var_export($x, true), '=>', var_export($y, true), "\n";
    } catch (Throwable $e) {
        echo $i, ':', var_export($x, true), '=>', get_class($e), ':', strtok($e->getMessage(), "\n"), "\n";
    }
}
--EXPECT--
0:NULL=>NULL
1:false=>Error:Call to a member function foo() on false
2:0=>Error:Call to a member function foo() on int
3:''=>Error:Call to a member function foo() on string
4:array (
)=>Error:Call to a member function foo() on array
5:true=>Error:Call to a member function foo() on true
6:1=>Error:Call to a member function foo() on int
