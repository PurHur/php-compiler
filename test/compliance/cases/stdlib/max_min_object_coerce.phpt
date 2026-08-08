--TEST--
stdlib max()/min() plain object — Zend Notice + coerce to 1 (#29123, ext/standard/math.c)
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
var_export(max(1, $o));
echo "\n";
var_export(max([1, $o]));
echo "\n";
var_export(min(1, $o));
echo "\n";
echo implode("\n", $notices), "\n";
--EXPECT--
1
1
1
Object of class stdClass could not be converted to int
Object of class stdClass could not be converted to int
Object of class stdClass could not be converted to int
