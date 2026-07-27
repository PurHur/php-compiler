--TEST--
stdlib array_combine() bool false key is empty string (#24034, ext/standard/array.c)
--FILE--
<?php
$a = array_combine([true, false], ['a', 'b']);
foreach (array_keys($a) as $k) {
    echo gettype($k), ':', var_export($k, true), "\n";
}
echo 'isset_empty=', isset($a['']) ? 'Y' : 'N', "\n";
echo 'vals=', $a[1], '|', $a[''], "\n";
--EXPECT--
integer:1
string:''
isset_empty=Y
vals=a|b
