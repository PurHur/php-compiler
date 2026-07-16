--TEST--
language: float array key write emits E_DEPRECATED on precision loss (#19730, zend_hash.c)
--FILE--
<?php
error_reporting(E_ALL);
$seen = [];
set_error_handler(static function (int $no, string $str) use (&$seen): bool {
    $seen[] = [$no, $str];
    return true;
});

$a = [];
$a[1.5] = 'x';
echo 'write_key=', var_export(array_key_first($a), true), "\n";
echo 'write_depr=', (isset($seen[0]) && E_DEPRECATED === $seen[0][0]) ? '1' : '0', "\n";
echo 'write_msg=', $seen[0][1] ?? '', "\n";

$seen = [];
$b = [];
$b[2.0] = 'y';
echo 'integral_depr=', empty($seen) ? '0' : '1', "\n";

$seen = [];
$c = [1 => 'z'];
$_ = $c[1.5];
echo 'read_depr=', empty($seen) ? '0' : '1', "\n";
$seen = [];
isset($c[1.5]);
echo 'isset_depr=', empty($seen) ? '0' : '1', "\n";
$seen = [];
unset($c[1.5]);
echo 'unset_depr=', empty($seen) ? '0' : '1', "\n";

$seen = [];
$d = [1.5 => 'lit'];
echo 'literal_depr=', (isset($seen[0]) && E_DEPRECATED === $seen[0][0]) ? '1' : '0', "\n";
echo 'literal_key=', var_export(array_key_first($d), true), "\n";
--EXPECT--
write_key=1
write_depr=1
write_msg=Implicit conversion from float 1.5 to int loses precision
integral_depr=0
read_depr=0
isset_depr=0
unset_depr=0
literal_depr=1
literal_key=1
