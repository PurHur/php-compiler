--TEST--
JIT version_compare(null) soft-null DEP+coerce on 8.4 (#21556, ext/standard/versioning.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--JIT--
--FILE--
<?php
error_reporting(E_ALL);
$seen = 0;
set_error_handler(static function (int $no) use (&$seen): bool {
    if (E_DEPRECATED === $no) {
        $seen++;
        return true;
    }
    return false;
});
echo version_compare(null, '1'), "\n";
echo version_compare('1', null), "\n";
restore_error_handler();
echo 'depr=', (int) ($seen >= 2), "\n";
?>
--EXPECT--
-1
1
depr=1
