<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT/VM: GlobIterator getFlags/setFlags (#34993).
 *
 * @see php-src ext/spl/spl_directory.c zim_FilesystemIterator_getFlags / setFlags
 *
 * @group llvm
 * @group aot
 */
final class Issue34993GlobIteratorFlagsAotTest extends TestCase
{
    private const EXPECTED = <<<'TXT'
get_excess:ArgumentCountError:FilesystemIterator::getFlags() expects exactly 0 arguments, 1 given
set_excess:ArgumentCountError:FilesystemIterator::setFlags() expects exactly 1 argument, 2 given
ok=1 flags=0 after=4096
TXT;

    public function testAotMatchesZend(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_34993_globiterator_flags_aot.php';
        $bin = sys_get_temp_dir().'/phpc_34993_gi_'.getmypid().'.bin';
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

    public function testVmMatchesZend(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/issue_34993_globiterator_flags_aot.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'issue_34993_globiterator_flags_aot.php');
        ob_start();
        $rt->run($block);
        $out = ob_get_clean();
        $this->assertSame(self::EXPECTED, $out);
    }

    public function testProxiesRegistered(): void
    {
        $ctx = (string) file_get_contents(dirname(__DIR__, 2).'/lib/JIT/Context.php');
        $this->assertStringContainsString('#34993', $ctx);
        $this->assertStringContainsString("'getFlags', 'setFlags'", $ctx);
        $method = (string) file_get_contents(
            dirname(__DIR__, 2).'/lib/JIT/Call/GlobIteratorMethod.php'
        );
        $this->assertStringContainsString('FilesystemIterator::getFlags', $method);
        $this->assertStringContainsString('FilesystemIterator::setFlags', $method);
    }
}
