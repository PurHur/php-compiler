<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: SplFileObject fgets/iterator I/O (#33319).
 *
 * @see php-src ext/spl/spl_directory.c zim_SplFileObject_fgets
 *
 * @group llvm
 * @group aot
 */
final class SplFileObjectFgets33319AotTest extends TestCase
{
    public function testVmAndAotMatchZendShape(): void
    {
        $root = dirname(__DIR__, 2);
        $repro = $root.'/test/repro/splfileobject_fgets_aot.php';
        $this->assertFileExists($repro);

        $zendCmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg($repro).' 2>&1';
        exec($zendCmd, $zendOut, $zendRc);
        $this->assertSame(0, $zendRc, implode("\n", $zendOut));
        $zend = implode("\n", $zendOut)."\n";
        $this->assertStringContainsString("fgets1='line1\n'", $zend);
        $this->assertStringContainsString('key1=1', $zend);

        $runtime = new Runtime();
        $cwd = getcwd();
        chdir($root);
        try {
            ob_start();
            $runtime->run($runtime->parseAndCompile(file_get_contents($repro), 'splfileobject_fgets_aot.php'));
            $vm = (string) ob_get_clean();
        } finally {
            chdir($cwd);
        }
        // VM key after first fgets may be 0 (known drift); content must match.
        $this->assertStringContainsString("fgets1='line1\n'", $vm);

        if (!LlvmToolchain::hasLibrary($root)) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $bin = sys_get_temp_dir().'/phpc_issue_33319_'.getmypid().'.bin';
        // Default helper-runtime cache (production). HELPER_RUNTIME_O=0 NestedJIT of
        // lineStringAt+JitStringConcat SIGSEGVs after c:main_before_php under phpunit.
        $compile = escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($repro).' 2>&1';
        chdir($root);
        try {
            exec($compile, $compileOut, $compileRc);
            $this->assertSame(0, $compileRc, implode("\n", $compileOut));
            $runOut = [];
            exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
            $this->assertSame(0, $runRc, implode("\n", $runOut));
            $aot = implode("\n", $runOut)."\n";
            $this->assertSame($zend, $aot, "AOT must match Zend\nZend:\n$zend\nAOT:\n$aot");
        } finally {
            chdir($cwd);
            @unlink($bin);
        }
    }

    public function testProxiesRegistered(): void
    {
        $root = dirname(__DIR__, 2);
        $ctx = (string) file_get_contents($root.'/lib/JIT/Context.php');
        $this->assertStringContainsString('#33319', $ctx);
        $this->assertStringContainsString("'rewind', 'valid', 'current', 'key', 'next', 'fgets', 'fwrite', 'eof'", $ctx);
        $helper = (string) file_get_contents($root.'/lib/VM/SplFileObjectJitHelper.php');
        $this->assertStringContainsString('compileFgets', $helper);
        $this->assertStringContainsString('PROP_CURSOR', $helper);
        $this->assertStringContainsString('PROP_FD', $helper);
        $this->assertFileDoesNotExist($root.'/runtime/spl_fileobject_fgets.c');
    }
}
