--TEST--
stdlib var_export($nested_builtin, true) — nested call arg evaluated (#10373, ext/standard/var.c)
--FILE--
<?php
declare(strict_types=1);

echo var_export(substr('hello', 0, -2), true), "\n";
echo var_export(explode(',', 'a,b', -1), true), "\n";
--EXPECT--
'hel'
array (
  0 => 'a',
)
