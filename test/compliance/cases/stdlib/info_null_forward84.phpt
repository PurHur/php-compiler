--TEST--
stdlib extension_loaded(null) soft-null on 8.4; path siblings soft/typed (#21281 / #20254)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
error_reporting(E_ALL);
$seen = [];
set_error_handler(static function (int $no, string $str) use (&$seen): bool {
    if (E_DEPRECATED === $no) {
        $seen[] = $str;
    }
    return true;
});
echo 'extension_loaded=', var_export(extension_loaded(null), true), "\n";
echo 'set_include_path=', var_export(set_include_path(null), true), "\n";
foreach ([
    'get_extension_funcs' => static fn () => get_extension_funcs(null),
    'version_compare' => static fn () => version_compare(null, '8.0'),
] as $name => $fn) {
    try {
        $fn();
        echo "fail $name\n";
    } catch (TypeError $e) {
        echo "ok $name\n";
    }
}
restore_error_handler();
echo 'depr=', (int) (count($seen) >= 2), "\n";
?>
--EXPECT--
extension_loaded=false
set_include_path=false
ok get_extension_funcs
ok version_compare
depr=1
