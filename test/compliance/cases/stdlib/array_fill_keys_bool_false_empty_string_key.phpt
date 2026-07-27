--TEST--
stdlib array_fill_keys() bool false key is empty string (#24033, ext/standard/array.c)
--FILE--
<?php
$a = array_fill_keys([true, false], 'v');
foreach (array_keys($a) as $k) {
    echo gettype($k), ':', var_export($k, true), "\n";
}
echo 'isset_empty=', isset($a['']) ? 'Y' : 'N', "\n";
echo 'key_exists_0=', array_key_exists(0, $a) ? 'Y' : 'N', "\n";
// Literal false dimension stays int 0 (zend_hash normalize), unlike fill_keys stringify.
echo 'literal=', gettype(array_keys([false => 'x'])[0]), ':', var_export(array_keys([false => 'x'])[0], true), "\n";
--EXPECT--
integer:1
string:''
isset_empty=Y
key_exists_0=N
literal=integer:0
