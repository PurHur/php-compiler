--TEST--
Language for-loop body $paths[] append preserves variable (#12712 family)
--FILE--
<?php
declare(strict_types=1);

$paths = [];
for ($i = 0; $i < 2; $i++) {
    $paths[] = $i;
}
var_export($paths);
echo "\n";

--EXPECT--
array (
  0 => 0,
  1 => 1,
)
