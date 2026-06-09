--TEST--
stdlib str_starts_with() — binary-safe NUL bytes JIT execute (#4390)
--FILE--
<?php
$hay = 'a'.chr(0).'b';
var_dump(str_starts_with($hay, 'a'.chr(0)));
var_dump(str_starts_with($hay, 'a'));
var_dump(str_starts_with($hay, 'b'));
var_dump(str_starts_with($hay, ''));
?>
--EXPECT--
bool(true)
bool(true)
bool(false)
bool(true)
