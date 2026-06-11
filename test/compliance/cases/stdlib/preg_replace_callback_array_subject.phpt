--TEST--
stdlib preg_replace_callback() array subject (issue #4442)
--FILE--
<?php
$out = preg_replace_callback('/a/', fn($m) => 'x', ['aa', 'bb']);
var_export($out);
echo "\n";
?>
--EXPECT--
array (
  0 => 'xx',
  1 => 'bb',
)
