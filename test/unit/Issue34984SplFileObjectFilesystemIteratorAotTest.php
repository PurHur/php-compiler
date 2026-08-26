<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: SplFileObject eof/fgets/fflush + FilesystemIterator::getFlags (#34984).
 *
 * Thin AOT leftover of #30937 — excess argc ACE + getFlags SKIP_DOTS default.
 *
 * @see php-src ext/spl/spl_directory.c zim_SplFileObject_eof / fgets / fflush
 * @see php-src ext/spl/spl_directory.c zim_FilesystemIterator_getFlags / ___construct
 *
 * @group llvm
 * @group aot
 */
final class Issue34984SplFileObjectFilesystemIteratorAotTest extends TestCase
{
    private const EXPECTED = <<<'TXT'
eof:ArgumentCountError:SplFileObject::eof() expects exactly 0 arguments, 1 given
fgets:ArgumentCountError:SplFileObject::fgets() expects exactly 0 arguments, 1 given
fflush:ArgumentCountError:SplFileObject::fflush() expects exactly 0 arguments, 1 given
flags:ArgumentCountError:FilesystemIterator::getFlags() expects exactly 0 arguments, 1 given
ok=1
TXT;

    public function testAotMatchesZendArgcAndGetFlags(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_30937_splfileobject_excess_argc.php';
        $bin = sys_get_temp_dir().'/phpc_34984_sfo_'.getmypid().'.bin';
        $compile = 'env PHP_COMPILER_HELPER_RUNTIME_O=0 PHP_COMPILER_LLVM_ASSERT=1 '
            .escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        $this->assertFileExists($bin);
        try {
            $runOut = [];
            exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
            $this->assertSame(0, $runRc, implode("\n", $runOut));
            $this->assertSame(self::EXPECTED, implode("\n", $runOut));
        } finally {
            @unlink($bin);
        }
    }

    public function testProxiesAndSkipDotsDefaultWired(): void
    {
        $ctx = (string) file_get_contents(
            dirname(__DIR__, 2).'/lib/JIT/Context.php'
        );
        $this->assertStringContainsString('#34984', $ctx);
        $this->assertStringContainsString("'getFlags', 'setFlags'", $ctx);
        $helper = (string) file_get_contents(
            dirname(__DIR__, 2).'/lib/VM/DirectoryIteratorJitHelper.php'
        );
        $this->assertStringContainsString('function compileGetFlags', $helper);
        $this->assertStringContainsString('function compileSetFlags', $helper);
        $this->assertStringContainsString('4096', $helper);
        $sfo = (string) file_get_contents(
            dirname(__DIR__, 2).'/lib/JIT/Call/SplFileObjectMethod.php'
        );
        $this->assertStringContainsString('emitArgumentCountErrorAndAbort', $sfo);
        $this->assertStringContainsString('SplFileObject::fflush', $sfo);
    }
}
