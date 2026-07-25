--TEST--
Language: #[\Deprecated] on trait is silent under PROFILE=8.4 (Zend 8.4 — rfc:deprecated_traits is 8.5+, #22989)
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
if (PHPCompiler\CompilerVersion::supportsDeprecatedTraitAttribute()) {
    die('skip trait deprecation enabled on this profile');
}
if (!PHPCompiler\CompilerVersion::supportsDeprecatedAttributeRuntimeNotices()) {
    die('skip requires Deprecated runtime notices gate (PROFILE=8.4)');
}
?>
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
error_reporting(E_ALL);
$seen = false;
set_error_handler(static function (int $errno, string $msg) use (&$seen): bool {
    $seen = true;
    echo sprintf("[%d] %s\n", $errno, $msg);
    return true;
});
if (true) {
    #[\Deprecated('old trait')]
    trait Tr {}
    class C { use Tr; }
}
echo $seen ? "noticed\n" : "silent\n";
--EXPECT--
silent
