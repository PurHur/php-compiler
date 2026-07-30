--TEST--
Language: #[\Override] on property without parent — compile-time fatal under PROFILE=8.5 (#9822, #25138)
--ENV--
PHP_COMPILER_PROFILE=8.5
--SKIPIF--
<?php
if (!PHPCompiler\CompilerVersion::supportsOverridePropertyAttribute()) {
    echo "skip — Override property target requires PROFILE≥8.5\n";
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
