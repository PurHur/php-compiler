--TEST--
Language: var_export($arr['key']->prop, true) exports the property, not the object (zend_execute.c FETCH_DIM_R + FETCH_OBJ_R, #31938)
--FILE--
<?php
class Simple
{
    public $name = 'test';
}

$obj = new Simple();
echo 'direct=', var_export($obj->name, true), "\n";

$arr = ['o' => new Simple()];
echo 'chained=', var_export($arr['o']->name, true), "\n";

$nested = [1 => ['o' => new Simple()]];
echo 'nested=', var_export($nested[1]['o']->name, true), "\n";

$b = [1 => [0 => 'a', 1 => 0]];
echo 'dim=', var_export($b[1][0], true), "\n";
--EXPECT--
direct='test'
chained='test'
nested='test'
dim='a'
