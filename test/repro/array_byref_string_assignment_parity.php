<?php
declare(strict_types=1);

function probe(string $label, mixed $value): void
{
    echo $label . '=' . var_export($value, true) . "\n";
}

$a = ['a' => 1, 'b' => 2];
foreach ($a as $k => &$v) { $v = $k . $v; }
unset($v);
probe('foreach_assoc_key', $a);

$b = [1, 2];
foreach ($b as &$v) { $v = 'n' . $v; }
unset($v);
probe('foreach_list', $b);

$arr = ['x' => 5, 'y' => 10];
array_walk($arr, static function (mixed &$value, mixed $key): void {
    $value = $key . $value;
});
probe('array_walk_string', $arr);
