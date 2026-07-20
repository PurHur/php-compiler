--TEST--
stdlib extension_loaded(null) soft-null on 8.4; version_compare soft; get_extension_funcs typed (#21281 / #21556 / #20254)
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
echo 'version_compare=', var_export(version_compare(null, '8.0'), true), "\n";
echo 'version_compare2=', var_export(version_compare('8.0', null), true), "\n";
try {
    get_extension_funcs(null);
    echo "fail get_extension_funcs\n";
} catch (TypeError $e) {
    echo "ok get_extension_funcs\n";
}
restore_error_handler();
echo 'depr=', (int) (count($seen) >= 2), "\n";
?>
--EXPECT--
extension_loaded=false
set_include_path=false
version_compare=-1
version_compare2=1
ok get_extension_funcs
depr=1
