--TEST--
stdlib sort()/rsort()/asort() mixed object+scalar — Zend Notice + object→1 (#29121, ext/standard/array.c)
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

function dump_vals(array $a): void {
    $parts = [];
    foreach ($a as $v) {
        $parts[] = is_object($v) ? 'obj' : (string) $v;
    }
    echo implode(',', $parts), "\n";
}

$a = [new stdClass(), 2, 1];
sort($a);
dump_vals($a);

$a = [2, 1, new stdClass()];
sort($a);
dump_vals($a);

$a = [new stdClass(), 2, 1];
rsort($a);
dump_vals($a);

$a = [new stdClass(), 2, 1];
asort($a);
$parts = [];
foreach ($a as $k => $v) {
    $parts[] = $k . ':' . (is_object($v) ? 'obj' : (string) $v);
}
echo implode(',', $parts), "\n";

echo implode("\n", $notices), "\n";
--EXPECT--
obj,1,2
1,obj,2
2,obj,1
0:obj,2:1,1:2
Object of class stdClass could not be converted to int
Object of class stdClass could not be converted to int
Object of class stdClass could not be converted to int
Object of class stdClass could not be converted to int
Object of class stdClass could not be converted to int
Object of class stdClass could not be converted to int
Object of class stdClass could not be converted to int
Object of class stdClass could not be converted to int
