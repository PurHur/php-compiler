--TEST--
Language: #[\Override] on property without parent — compile-time fatal (#9822, #25138 PHP 8.5+)
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
if (!PHPCompiler\CompilerVersion::supportsOverridePropertyTarget()) {
    echo "skip — #[\\Override] on properties requires PROFILE≥8.5 (#25138)\n";
}
?>
--ENV--
PHP_COMPILER_PROFILE=8.5
--FILE--
<?php
declare(strict_types=1);

class C {
    #[\Override]
    public int $x = 1;
}
echo "ok\n";
--EXPECT_EXIT--
255
--EXPECTF--
parseAndCompile failure: target=%s: C::$x has #[\Override] attribute, but no matching parent property exists
