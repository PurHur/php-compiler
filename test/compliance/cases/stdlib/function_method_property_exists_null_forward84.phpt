--TEST--
stdlib function_exists()/method_exists()/property_exists(null) soft-null on 8.4 (#21281, re-#20360)
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
echo 'function_exists=', var_export(function_exists(null), true), "\n";
echo 'method_exists=', var_export(method_exists('stdClass', null), true), "\n";
echo 'property_exists=', var_export(property_exists('stdClass', null), true), "\n";
echo 'class_exists=', var_export(class_exists(null), true), "\n";
restore_error_handler();
echo 'depr=', (int) (count($seen) >= 4), "\n";
?>
--EXPECT--
function_exists=false
method_exists=false
property_exists=false
class_exists=false
depr=1
