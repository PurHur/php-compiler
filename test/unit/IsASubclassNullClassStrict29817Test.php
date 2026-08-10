<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * is_a/is_subclass_of(null $class) under declare(strict_types=1) TypeError (#29817).
 *
 * php-src: Zend/zend_builtin_functions.c — PHP_FUNCTION(is_a) / is_subclass_of Z_PARAM_STR
 */
final class IsASubclassNullClassStrict29817Test extends TestCase
{
    public function testVmThrowsTypeErrorUnderStrictTypes(): void
    {
        $out = $this->runRepro('bin/vm.php');
        $this->assertStrictTypeErrors($out);
    }

    public function testJitThrowsTypeErrorUnderStrictTypes(): void
    {
        $out = $this->runRepro('bin/jit.php');
        $this->assertStrictTypeErrors($out);
    }

    private function assertStrictTypeErrors(string $out): void
    {
        $this->assertStringContainsString(
            'ok:is_a_string:is_a(): Argument #2 ($class) must be of type string, null given',
            $out
        );
        $this->assertStringContainsString(
            'ok:is_a_object:is_a(): Argument #2 ($class) must be of type string, null given',
            $out
        );
        $this->assertStringContainsString(
            'ok:is_subclass_of:is_subclass_of(): Argument #2 ($class) must be of type string, null given',
            $out
        );
        $this->assertStringNotContainsString('fail:', $out);
    }

    private function runRepro(string $bin): string
    {
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/maintainer_gap_is_a_subclass_null_class_strict.php';
        $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/'.$bin)
            .' '.escapeshellarg($src).' 2>&1';
        exec($cmd, $out, $rc);
        $this->assertSame(0, $rc, implode("\n", $out));

        return implode("\n", $out).([] === $out ? '' : "\n");
    }
}
