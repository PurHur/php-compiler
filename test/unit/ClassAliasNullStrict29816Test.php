<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * class_alias(null) under declare(strict_types=1) TypeError (#29816).
 *
 * php-src: Zend/zend_builtin_functions.c — PHP_FUNCTION(class_alias) Z_PARAM_STR
 * Soft (non-strict) DEP path remains covered by ClassAliasNullDep29661Test.
 */
final class ClassAliasNullStrict29816Test extends TestCase
{
    public function testVmThrowsTypeErrorUnderStrictTypes(): void
    {
        $out = $this->runRepro('bin/vm.php');
        $this->assertStrictTypeError($out);
    }

    public function testJitThrowsTypeErrorUnderStrictTypes(): void
    {
        $out = $this->runRepro('bin/jit.php');
        $this->assertStrictTypeError($out);
    }

    private function assertStrictTypeError(string $out): void
    {
        $this->assertStringContainsString(
            'ok:class_alias:class_alias(): Argument #1 ($class) must be of type string, null given',
            $out
        );
        $this->assertStringNotContainsString('fail:class_alias:no_throw', $out);
        $this->assertStringNotContainsString('Class "" not found', $out);
    }

    private function runRepro(string $bin): string
    {
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/maintainer_gap_class_alias_null_strict.php';
        $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/'.$bin)
            .' '.escapeshellarg($src).' 2>&1';
        exec($cmd, $out, $rc);
        $this->assertSame(0, $rc, implode("\n", $out));

        return implode("\n", $out).([] === $out ? '' : "\n");
    }
}
