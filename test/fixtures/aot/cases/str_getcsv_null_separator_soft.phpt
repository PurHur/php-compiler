--TEST--
AOT: str_getcsv() null optional string args DEP+defaults (#21734, ext/standard/file.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
error_reporting(E_ALL);
$deps = 0;
set_error_handler(static function (int $no, string $msg) use (&$deps): bool {
    ++$deps;
    return true;
});
$row = str_getcsv('a,b', null);
echo ($deps > 0 ? 'DEP' : 'nodep'), ' ', implode(',', $row), "\n";
?>
--EXPECT--
DEP a,b
