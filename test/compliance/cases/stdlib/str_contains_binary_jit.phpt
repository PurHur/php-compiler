--TEST--
stdlib str_contains() — binary-safe NUL bytes JIT execute (#4146)
--FILE--
<?php
$h = 'a'.chr(0).'needle';
var_dump(str_contains($h, chr(0)));
var_dump(str_contains($h, 'needle'));
var_dump(str_contains($h, chr(0).'needle'));
var_dump(str_contains('a'.chr(0).'b', chr(0).'b'));
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
bool(true)
