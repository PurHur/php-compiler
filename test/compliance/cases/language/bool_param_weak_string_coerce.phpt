--TEST--
Language: weak bool param coerces string via convert_to_boolean (#29860, Zend/zend_operators.c)
--FILE--
<?php
function f(bool $x): bool
{
    return $x;
}

foreach (['x', '', '0', '1', 'false', 'off', 'no', 'yes', '0.0', 0, 1, 0.0, 1.5] as $v) {
    echo json_encode($v), '=>';
    try {
        var_export(f($v));
    } catch (Throwable $e) {
        echo get_class($e);
    }
    echo "\n";
}
--EXPECT--
"x"=>true
""=>false
"0"=>false
"1"=>true
"false"=>true
"off"=>true
"no"=>true
"yes"=>true
"0.0"=>true
0=>false
1=>true
0=>false
1.5=>true
