<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\FilePutContentsJitHelper;
use PHPUnit\Framework\TestCase;

/**
 * __compiler_file_put_contents shrink guards (#15310, #19966, #20266, #20290).
 *
 * Always-helper via JitVmHelperLink; libc leaf only from phpc_*_kernel (JitFilePutContentsLibc emitBody).
 */
final class FilePutContentsRuntimeShrinkTest extends TestCase
{
    public function testStringFilePutContentsAlwaysHelperNoThinFork(): void
    {
        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringFilePutContents.php');
        $this->assertStringContainsString('FilePutContentsJitHelper', $bridge);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $bridge);
        $this->assertStringContainsString('extractLongFromHelperResult', $bridge);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $bridge);
        $this->assertStringNotContainsString('isThinStandaloneAotMain', $bridge);
        $this->assertStringNotContainsString('implementLibcBody', $bridge);
        $this->assertStringNotContainsString('JitFilePutContentsLibc', $bridge);
        $this->assertStringNotContainsString('JitFilePutContentsKernel', $bridge);
        $this->assertStringNotContainsString('StringFilePutContentsLibc', $bridge);
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/StringFilePutContentsLibc.php');
        $this->assertFileDoesNotExist(__DIR__.'/../../ext/standard/JitFilePutContentsKernel.php');
        // Kernel emitBody still owns thin libc IR (#20290).
        $this->assertFileExists(__DIR__.'/../../ext/standard/JitFilePutContentsLibc.php');
        $this->assertFileExists(__DIR__.'/../../ext/standard/phpc_file_put_contents_kernel.php');
    }

    public function testFilePutContentsJitHelperUsesPhpcKernel(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/FilePutContentsJitHelper.php');
        $this->assertMatchesRegularExpression('/return\s+\\\\phpc_file_put_contents_kernel\s*\(/', $source);
        $kernel = (string) file_get_contents(__DIR__.'/../../ext/standard/phpc_file_put_contents_kernel.php');
        $this->assertStringContainsString('JitLongArg::lower', $kernel);
        $this->assertStringNotContainsString('truncOrBitCast', $kernel);
    }

    public function testFilePutContentsJitHelperWritesViaKernel(): void
    {
        if (!\function_exists('phpc_file_put_contents_kernel')) {
            $this->markTestSkipped('phpc_file_put_contents_kernel requires compiler runtime');
        }
        $path = tempnam(sys_get_temp_dir(), 'phpc-fpc-');
        $this->assertNotFalse($path);
        $written = FilePutContentsJitHelper::writePathArgv($path, 'put-ok', 0);
        $this->assertSame(6, $written);
        $this->assertSame('put-ok', file_get_contents($path));
        $this->assertSame(-1, FilePutContentsJitHelper::writePathArgv($path.'/missing-20266', 'x', 0));
        @unlink($path);
    }

    public function testSpineBundleIncludesFilePutContentsPhpPath(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('FilePutContentsJitHelper.php', $spine);
        $this->assertStringContainsString('StringFilePutContents.php', $spine);
        $this->assertStringContainsString('phpc_file_put_contents_kernel.php', $spine);
        $this->assertStringContainsString('JitFilePutContentsLibc.php', $spine);
        $this->assertStringNotContainsString('JitFilePutContentsKernel.php', $spine);
    }

    public function testNestedHelperCoerceHasScopedLongExtractNotGlobalReadLong(): void
    {
        $coerce = (string) file_get_contents(__DIR__.'/../../lib/JIT/JitNestedHelperCoerce.php');
        $this->assertStringContainsString('extractLongFromHelperResult', $coerce);
        if (preg_match(
            '/function coerceHelperScalarResult\(.*?^\s{4}\}/ms',
            $coerce,
            $m
        )) {
            $this->assertStringNotContainsString('__value__readLong', $m[0]);
        } else {
            $this->fail('coerceHelperScalarResult not found');
        }
    }

    public function testContextResolvesFpcKernelsFromRuntimeModulesBeforeRegister(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Context.php');
        $this->assertStringContainsString('isPreRegisterModuleNestedJitKernel', $source);
        $this->assertStringContainsString('phpc_file_put_contents_kernel', $source);
        $this->assertStringNotContainsString('phpc_readfile_kernel', $source);
        $this->assertStringContainsString("'readfile'", $source);
        $this->assertStringContainsString('runtime->modules', $source);
    }
}
