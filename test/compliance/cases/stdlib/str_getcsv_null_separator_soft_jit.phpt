--TEST--
stdlib str_getcsv() — null optional string args DEP+defaults JIT (#21734, re-#21207, ext/standard/file.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--JIT--
--FILE--
<?php
error_reporting(E_ALL);
$deps = 0;
set_error_handler(static function (int $no, string $msg) use (&$deps): bool {
    ++$deps;
    return true;
});
$row = str_getcsv('a,b', null);
echo 'separator ', ($deps > 0 ? 'DEP' : 'nodep'), ' ', var_export($row, true), "\n";
$deps = 0;
$row = str_getcsv('a,b', ',', null);
echo 'enclosure ', ($deps > 0 ? 'DEP' : 'nodep'), ' ', var_export($row, true), "\n";
$deps = 0;
$row = str_getcsv('a,b', ',', '"', null);
echo 'escape ', ($deps > 0 ? 'DEP' : 'nodep'), ' ', var_export($row, true), "\n";
?>
--EXPECT--
separator DEP array (
  0 => 'a',
  1 => 'b',
)
enclosure DEP array (
  0 => 'a',
  1 => 'b',
)
escape DEP array (
  0 => 'a',
  1 => 'b',
)
