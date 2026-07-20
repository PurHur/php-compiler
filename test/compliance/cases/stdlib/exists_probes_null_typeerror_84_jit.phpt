--TEST--
JIT interface_exists()/trait_exists()/enum_exists()/class_exists(null) soft-null on 8.4 (#21281, re-#19223)
--ENV--
PHP_COMPILER_PROFILE=8.4
--JIT--
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
foreach (['interface_exists', 'trait_exists', 'enum_exists', 'class_exists'] as $fn) {
    echo $fn, '=', var_export($fn(null), true), "\n";
}
restore_error_handler();
echo 'depr=', (int) (count($seen) >= 4), "\n";
?>
--EXPECT--
interface_exists=false
trait_exists=false
enum_exists=false
class_exists=false
depr=1
