<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\BuiltinInternalArgInfo;
use PHPCompiler\BuiltinParamNames;
use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * hrtime Reflection as_number optional default false (#25146).
 *
 * @see php-src ext/standard/basic_functions.stub.php
 * @see \PHPCompiler\BuiltinParamNames
 *
 * @group llvm
 * @group aot
 */
final class Issue25146HrtimeReflectionDefaultTest extends TestCase
{
    private const VM_EXPECT = "optional=yes def=false\npair_len=2\n";

    private const AOT_RUNTIME_EXPECT = "zero=2 named=2\n";

    public function testBuiltinParamNamesMarksAsNumberOptional(): void
    {
        $names = BuiltinParamNames::forFunction('hrtime');
        $this->assertSame(['as_number='], $names);
        $info = BuiltinInternalArgInfo::paramInfoForFunction('hrtime', 0);
        $this->assertNotNull($info);
        $this->assertTrue($info['isOptional']);
        $this->assertSame('bool', $info['type']);
    }

    public function testVmReflectionOptionalDefaultFalse(): void
    {
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_25146_hrtime_reflection_default.php';
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

    public function testAotZeroArgRuntimeUnchanged(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_25146_hrtime_zero_arg_aot.php';
        $bin = sys_get_temp_dir().'/phpc_25146_aot_'.getmypid().'.bin';
        $compile = 'env PHP_COMPILER_HELPER_RUNTIME_O=0 '
            .escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        try {
            exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
            $joined = implode("\n", $runOut);
            if ('' !== $joined && !str_ends_with($joined, "\n")) {
                $joined .= "\n";
            }
            $this->assertSame(0, $runRc, $joined);
            $this->assertSame(self::AOT_RUNTIME_EXPECT, $joined);
        } finally {
            @unlink($bin);
        }
    }
}
