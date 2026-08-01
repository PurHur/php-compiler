--TEST--
Language: null array offset / array_key_exists(null) E_DEPRECATED under PROFILE=8.5 (#26276)
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
if (!PHPCompiler\CompilerVersion::supportsNullArrayOffsetDeprecation()) {
    die('skip requires PHP 8.5+ null array offset deprecation');
}
?>
--ENV--
PHP_COMPILER_PROFILE=8.5
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
echo 'write_depr=', (isset($seen[0]) && E_DEPRECATED === $seen[0][0]) ? '1' : '0', "\n";
echo 'write_msg=', $seen[0][1] ?? '', "\n";

$seen = [];
$_ = $a[null];
echo 'read_val=', var_export($_, true), "\n";
echo 'read_depr=', (isset($seen[0]) && E_DEPRECATED === $seen[0][0]) ? '1' : '0', "\n";
echo 'read_msg=', $seen[0][1] ?? '', "\n";

$seen = [];
$exists = array_key_exists(null, $a);
echo 'ake=', $exists ? '1' : '0', "\n";
echo 'ake_depr=', (isset($seen[0]) && E_DEPRECATED === $seen[0][0]) ? '1' : '0', "\n";
echo 'ake_msg=', $seen[0][1] ?? '', "\n";
--EXPECT--
write_key=''
write_depr=1
write_msg=Using null as an array offset is deprecated, use an empty string instead
read_val=1
read_depr=1
read_msg=Using null as an array offset is deprecated, use an empty string instead
ake=1
ake_depr=1
ake_msg=Using null as the key parameter for array_key_exists() is deprecated, use an empty string instead
