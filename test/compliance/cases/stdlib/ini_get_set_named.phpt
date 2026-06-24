--TEST--
stdlib ini_get()/ini_set() option:/value: named parameters (#10028, ext/standard/basic_functions.c)
--FILE--
<?php
$before = ini_get(option: 'display_errors');
ini_set(option: 'display_errors', value: '0');
$mid = ini_get(option: 'display_errors');
ini_set(option: 'display_errors', value: $before);
$after = ini_get(option: 'display_errors');
echo ($mid === '' && $after === $before) ? "ok\n" : "fail\n";
--EXPECT--
ok
