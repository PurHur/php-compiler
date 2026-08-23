<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\VM\ExceptionSupport;
use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * AOT: throw non-Throwable object must raise Error, not SIGSEGV (#33975).
 *
 * @see php-src Zend/zend_exceptions.c zend_throw_exception_internal
 * @see \PHPCompiler\JIT\TryCatchHelper::emitThrow
 *
 * @group llvm
 * @group aot
 */
final class Issue33975ThrowNonThrowableAotTest extends TestCase
{
    public function testTryCatchHelperUncaughtPathGuardsThrowable(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2).'/lib/JIT/TryCatchHelper.php'
        );
        $this->assertStringContainsString('throw_uncaught_non_throwable', $source);
        $this->assertStringContainsString('#33975', $source);
        $this->assertStringContainsString(
            "ReflectionBuiltinHelper::emitInstanceOf(\$context, \$thrown, 'Throwable')",
            $source
        );
        $this->assertStringContainsString(
            'ExceptionSupport::THROW_NON_THROWABLE_MESSAGE',
            $source
        );
    }

    public function testAotThrowNonThrowableRaisesErrorNotSegfault(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_33975_throw_non_throwable_aot.php';
        $bin = sys_get_temp_dir().'/phpc_33975_non_throwable_'.getmypid().'.bin';
        $compile = 'env PHP_COMPILER_HELPER_RUNTIME_O=0 '
            .escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        $this->assertFileExists($bin);
        try {
            $runOut = [];
            exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
            $joined = implode("\n", $runOut);
            $this->assertNotSame(
                139,
                $runRc,
                "thin AOT must not SIGSEGV on non-Throwable throw (#33975)\n".$joined
            );
            $this->assertSame(255, $runRc, $joined);
            $this->assertStringContainsString(
                ExceptionSupport::THROW_NON_THROWABLE_MESSAGE,
                $joined
            );
        } finally {
            @unlink($bin);
        }
    }
}
