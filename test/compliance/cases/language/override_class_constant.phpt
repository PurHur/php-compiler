--TEST--
Language: #[\Override] on class constants is rejected — not a php-src target (#26253)
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

interface I {
    public const X = 1;
}

class C implements I {
    #[\Override]
    public const X = 2;
}

echo C::X, "\n";
?>
--EXPECT_EXIT--
255
--EXPECTF--
parseAndCompile failure: target=%s: Attribute "Override" cannot target class constant (allowed targets: method)
