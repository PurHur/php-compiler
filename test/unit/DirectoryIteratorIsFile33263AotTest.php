<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: DirectoryIterator::isFile()/isDir() on foreach :object receivers (#33263).
 *
 * @see php-src ext/spl/spl_directory.c zim_SplFileInfo_isFile
 *
 * @group llvm
 * @group aot
 */
final class DirectoryIteratorIsFile33263AotTest extends TestCase
{
    private const EXPECTED = "file=a.txt\nfiles=1 dirs=0\n";

    public function testVmDirectoryIteratorIsFile(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(
            dirname(__DIR__).'/repro/directoryiterator_isfile_aot_33263.php'
        );
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'directoryiterator_isfile_aot_33263.php'));
        $out = (string) ob_get_clean();
        $this->assertSame(self::EXPECTED, $out);
    }

    public function testAotDirectoryIteratorIsFile(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/directoryiterator_isfile_aot_33263.php';
        $bin = sys_get_temp_dir().'/phpc_di_isfile_33263_'.getmypid().'.bin';
        try {
            $compile = 'env PHP_COMPILER_HELPER_RUNTIME_O=0 '
                .escapeshellarg(PHP_BINARY).' '
                .escapeshellarg($root.'/bin/compile.php')
                .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
            exec($compile, $compileOut, $compileRc);
            $this->assertSame(0, $compileRc, implode("\n", $compileOut));
            $this->assertFileExists($bin);
            $runOut = [];
            exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
            $this->assertSame(0, $runRc, implode("\n", $runOut));
            $this->assertSame(self::EXPECTED, implode("\n", $runOut)."\n");
        } finally {
            if (is_file($bin)) {
                @unlink($bin);
            }
        }
    }
}
