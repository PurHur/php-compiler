--TEST--
stdlib in_array()/array_search() loose object==int — Zend Notice + coerce (#29122, ext/standard/array.c)
--FILE--
<?php
error_reporting(E_ALL);
$notices = [];
set_error_handler(function (int $no, string $str) use (&$notices): bool {
    if ($no === E_NOTICE) {
        $notices[] = $str;
    }
    return true;
});

$o = new stdClass();
var_export(in_array(1, [$o], false));
echo "\n";
var_export(in_array(0, [$o], false));
echo "\n";
$a = [$o, 1, '1'];
var_export(array_search(1, $a, false));
echo "\n";
var_export($o == 1);
echo "\n";
echo implode("\n", $notices), "\n";
--EXPECT--
true
false
0
true
Object of class stdClass could not be converted to int
Object of class stdClass could not be converted to int
Object of class stdClass could not be converted to int
Object of class stdClass could not be converted to int
