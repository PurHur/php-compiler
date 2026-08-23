<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\BuiltinInternalArgInfo;
use PHPUnit\Framework\TestCase;

/**
 * phpinfo(): true Reflection return (#27728, re-#24550).
 *
 * @see php-src ext/standard/basic_functions.stub.php
 */
final class Issue27728PhpinfoReflectionTrueTest extends TestCase
{
    private const VM_EXPECT = "hasReturn=yes\ntype=true\n";

    public function testStubReturnTypeLabelIsTrue(): void
    {
        $this->assertSame('true', BuiltinInternalArgInfo::stubReturnTypeLabelForFunction('phpinfo'));
    }

    public function testVmReflectionReturnTrue(): void
    {
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_27728_phpinfo_reflection_true.php';
        $cmd = escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/vm.php').' '
            .escapeshellarg($src).' 2>&1';
        exec($cmd, $out, $rc);
        $joined = implode("\n", $out);
        if ('' !== $joined && !str_ends_with($joined, "\n")) {
            $joined .= "\n";
        }
        $this->assertSame(0, $rc, $joined);
        $this->assertSame(self::VM_EXPECT, $joined);
    }
}
