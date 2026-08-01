--TEST--
Language: #[\Override] on class constant without parent — target fatal (#26253)
--SKIPIF--
<?php
if (!PHPCompiler\CompilerVersion::supportsOverrideAttribute()) {
    echo "skip — Override validation disabled on reference profile\n";
}
?>
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
declare(strict_types=1);

class C {
    #[\Override]
    public const X = 1;
}

echo C::X, "\n";
--EXPECT_EXIT--
255
--EXPECTF--
parseAndCompile failure: target=%s: Attribute "Override" cannot target class constant (allowed targets: method)
