--TEST--
Language: #[\Override] on property without parent — compile-time fatal (#9822)
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
    public int $x = 1;
}
echo "ok\n";
--EXPECT_EXIT--
255
