<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT leftover of #30937 — SplFileObject / FilesystemIterator excess argc (#34984).
 *
 * php-src: ext/spl/spl_directory.c
 *
 * @group llvm
 * @group aot
 */
final class Issue34984SplFileObjectExcessArgcAotTest extends TestCase
{
    public function testCallProxiesGuardExactZeroUserArgs(): void
    {
        $sfo = file_get_contents(__DIR__.'/../../lib/JIT/Call/SplFileObjectMethod.php');
        $this->assertNotFalse($sfo);
        $this->assertStringContainsString('emitArgumentCountErrorAndAbort', $sfo);
        $this->assertStringContainsString("'SplFileObject::eof'", $sfo);
        $this->assertStringContainsString("'fgets'", $sfo);
        $this->assertStringContainsString("'SplFileObject::fflush'", $sfo);

        $di = file_get_contents(__DIR__.'/../../lib/JIT/Call/DirectoryIteratorMethod.php');
        $this->assertNotFalse($di);
        $this->assertStringContainsString('getFlags', $di);
        $this->assertStringContainsString('emitArgumentCountErrorAndAbort', $di);

        $ctx = file_get_contents(__DIR__.'/../../lib/JIT/Context.php');
        $this->assertNotFalse($ctx);
        $this->assertStringContainsString("'getFlags'", $ctx);
        $this->assertStringContainsString("'setFlags'", $ctx);
    }

    public function testVmReproStillMatchesZendWording(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/issue_34984_sfo_fi_argc_aot.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'issue_34984_sfo_fi_argc_aot.php');
        ob_start();
        $rt->run($block);
        $out = ob_get_clean();
        $this->assertStringContainsString(
            'eof:ArgumentCountError:SplFileObject::eof() expects exactly 0 arguments, 1 given',
            $out
        );
        $this->assertStringContainsString(
            'fgets:ArgumentCountError:SplFileObject::fgets() expects exactly 0 arguments, 1 given',
            $out
        );
        $this->assertStringContainsString(
            'fflush:ArgumentCountError:SplFileObject::fflush() expects exactly 0 arguments, 1 given',
            $out
        );
        $this->assertStringContainsString(
            'flags:ArgumentCountError:FilesystemIterator::getFlags() expects exactly 0 arguments, 1 given',
            $out
        );
        $this->assertStringContainsString('flags0=4096', $out);
        $this->assertStringContainsString('ok=1', $out);
    }

    public function testAotExcessArgcAndGetFlagsDefault(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_34984_sfo_fi_argc_aot.php';
        $bin = sys_get_temp_dir().'/phpc_34984_'.getmypid().'.bin';
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
            $text = implode("\n", $runOut)."\n";
            $this->assertStringContainsString(
                "eof:ArgumentCountError:SplFileObject::eof() expects exactly 0 arguments, 1 given\n",
                $text
            );
            $this->assertStringContainsString(
                "fgets:ArgumentCountError:SplFileObject::fgets() expects exactly 0 arguments, 1 given\n",
                $text
            );
            $this->assertStringContainsString(
                "fflush:ArgumentCountError:SplFileObject::fflush() expects exactly 0 arguments, 1 given\n",
                $text
            );
            $this->assertStringContainsString(
                "flags:ArgumentCountError:FilesystemIterator::getFlags() expects exactly 0 arguments, 1 given\n",
                $text
            );
            $this->assertStringContainsString("flags0=4096\n", $text);
            $this->assertStringContainsString("ok=1\n", $text);
            $this->assertStringNotContainsString('ACCEPTED', $text);
        } finally {
            @unlink($bin);
        }
    }
}
