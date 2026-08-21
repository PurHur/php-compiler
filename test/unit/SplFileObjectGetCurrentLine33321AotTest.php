<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: SplFileObject::getCurrentLine is the fgets alias (#33321).
 *
 * @see php-src ext/spl/spl_directory.c zim_SplFileObject_getCurrentLine
 *
 * @group llvm
 * @group aot
 */
final class SplFileObjectGetCurrentLine33321AotTest extends TestCase
{
    public function testAotMatchesZendGetCurrentLine(): void
    {
        $root = dirname(__DIR__, 2);
        $repro = $root.'/test/repro/splfileobject_getcurrentline_aot.php';
        $this->assertFileExists($repro);

        $zendCmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg($repro).' 2>&1';
        exec($zendCmd, $zendOut, $zendRc);
        $this->assertSame(0, $zendRc, implode("\n", $zendOut));
        $zend = implode("\n", $zendOut)."\n";
        $this->assertStringContainsString('cur0="line1\n"', $zend);
        $this->assertStringContainsString('gcl1="line2\n"', $zend);
        $this->assertStringContainsString('fgets="line3\n"', $zend);

        if (!LlvmToolchain::hasLibrary($root)) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $bin = sys_get_temp_dir().'/phpc_issue_33321_'.getmypid().'.bin';
        $compile = 'env PHP_COMPILER_HELPER_RUNTIME_O=0 '.escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($repro).' 2>&1';
        $cwd = getcwd();
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

    public function testNoNewRuntimeCAndProxies(): void
    {
        $root = dirname(__DIR__, 2);
        $this->assertFileDoesNotExist($root.'/runtime/spl_fileobject_getcurrentline.c');
        $call = (string) file_get_contents($root.'/lib/JIT/Call/SplFileObjectMethod.php');
        $this->assertStringContainsString('getcurrentline', $call);
        $this->assertStringContainsString('#33321', $call);
        $ctx = (string) file_get_contents($root.'/lib/JIT/Context.php');
        $this->assertStringContainsString("'getCurrentLine'", $ctx);
        $this->assertStringContainsString('#33321', $ctx);
    }
}
