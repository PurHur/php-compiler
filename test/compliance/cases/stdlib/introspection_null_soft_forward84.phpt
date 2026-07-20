--TEST--
stdlib introspection null soft-coerce on 8.4 (#21281)
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
foreach ([
    'function_exists' => static fn () => function_exists(null),
    'class_exists' => static fn () => class_exists(null),
    'interface_exists' => static fn () => interface_exists(null),
    'trait_exists' => static fn () => trait_exists(null),
    'enum_exists' => static fn () => enum_exists(null),
    'extension_loaded' => static fn () => extension_loaded(null),
    'defined' => static fn () => defined(null),
] as $name => $fn) {
    echo $name, '=', var_export($fn(), true), "\n";
}
try {
    constant(null);
    echo "constant uncaught\n";
} catch (Throwable $e) {
    echo 'constant ', get_class($e), ': ', $e->getMessage(), "\n";
}
restore_error_handler();
echo 'depr=', (int) (count($seen) >= 8), "\n";
?>
--EXPECT--
function_exists=false
class_exists=false
interface_exists=false
trait_exists=false
enum_exists=false
extension_loaded=false
defined=false
constant Error: Undefined constant ""
depr=1
