--TEST--
Language: #[\Deprecated] on trait emits E_USER_DEPRECATED when used (PHP 8.5+, #22989, rfc:deprecated_traits)
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
if (!PHPCompiler\CompilerVersion::supportsDeprecatedTraitAttribute()) {
    die('skip requires PHP 8.5+ deprecated trait attribute');
}
?>
--ENV--
PHP_COMPILER_PROFILE=8.5
--FILE--
<?php
error_reporting(E_ALL);
set_error_handler(static function (int $errno, string $msg): bool {
    echo sprintf("[%d] %s\n", $errno, $msg);
    return true;
});
// Conditional so declaration runs after set_error_handler (top-level DECLARE_* hoist).
if (true) {
    #[\Deprecated('old trait')]
    trait Tr {}
    class C { use Tr; }
}
echo "done\n";
--EXPECT--
[16384] Trait Tr used by C is deprecated, old trait
done
