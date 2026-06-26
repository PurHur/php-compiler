--TEST--
Language: invalid #[\Override] on class constant without parent (#9821, zend_attributes.c)
--SKIPIF--
<?php
if (!PHPCompiler\CompilerVersion::supportsOverrideAttribute()) {
    echo "skip — Override validation disabled on reference profile\n";
}
?>
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
