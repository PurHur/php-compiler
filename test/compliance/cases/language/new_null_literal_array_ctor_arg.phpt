--TEST--
Language: new Class(null, [...]) keeps null on arg #1 (issue #22770, Zend/zend_compile.c)
--FILE--
<?php
class C
{
    public function __construct($a, $b)
    {
        echo 'a=', var_export($a, true), ' b=', gettype($b), "\n";
    }
}
new C(null, ['k' => 1]);
$n = null;
new C($n, ['k' => 1]);
function f($a, $b)
{
    echo 'f a=', var_export($a, true), ' b=', gettype($b), "\n";
}
f(null, ['k' => 1]);
--EXPECT--
a=NULL b=array
a=NULL b=array
f a=NULL b=array
