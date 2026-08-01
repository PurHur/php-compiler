--TEST--
Language: null array offset silent under PROFILE=8.4 (no 8.5 deprecation) (#26276)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
error_reporting(E_ALL);
$seen = [];
set_error_handler(static function (int $no, string $str) use (&$seen): bool {
    $seen[] = [$no, $str];
    return true;
});

$a = [];
$a[null] = 1;
echo 'write_key=', var_export(array_key_first($a), true), "\n";
echo 'write_depr=', empty($seen) ? '0' : '1', "\n";

$seen = [];
$_ = $a[null];
echo 'read_val=', var_export($_, true), "\n";
echo 'read_depr=', empty($seen) ? '0' : '1', "\n";

$seen = [];
$exists = array_key_exists(null, $a);
echo 'ake=', $exists ? '1' : '0', "\n";
echo 'ake_depr=', empty($seen) ? '0' : '1', "\n";
--EXPECT--
write_key=''
write_depr=0
read_val=1
read_depr=0
ake=1
ake_depr=0
