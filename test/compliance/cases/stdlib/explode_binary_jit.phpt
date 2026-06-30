--TEST--
stdlib explode() — binary-safe NUL delimiter JIT compile (#14019)
--FILE--
<?php
$h = 'aa'.chr(0).'bb';
var_export(explode('bb', $h));
echo "\n";
?>
--EXPECT--
array (
  0 => 'aa' . "\0" . '',
  1 => '',
)
