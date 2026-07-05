--TEST--
stdlib get_defined_functions() — phantom builtins withheld on forward 8.4 profile (#16482)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
declare(strict_types=1);

$fail = [];
if (function_exists('str_increment')) {
    $fail[] = 'str_increment';
}
if (function_exists('str_decrement')) {
    $fail[] = 'str_decrement';
}
$internal = get_defined_functions()['internal'] ?? [];
if (\in_array('str_increment', $internal, true)) {
    $fail[] = 'str_increment_internal';
}
if (\in_array('str_decrement', $internal, true)) {
    $fail[] = 'str_decrement_internal';
}
if (\in_array('fpow', $internal, true)) {
    $fail[] = 'fpow_internal';
}
if (\in_array('nextafter', $internal, true)) {
    $fail[] = 'nextafter_internal';
}

echo [] === $fail ? "ok\n" : 'fail: '.implode(',', $fail)."\n";
--EXPECT--
ok
