<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: SplFileObject::current / foreach / seek must Module-verify and match Zend (#33521).
 *
 * Root cause: JitExplode::ensureFindDelimFunction emitted VmStringCompare::findOffset
 * while loweringLlvmFunction was still the user method — peer StringStrstr (#27211).
 *
 * @see php-src ext/spl/spl_directory.c zim_SplFileObject_current
 * @see php-src ext/standard/string.c PHP_FUNCTION(explode)
 *
 * @group llvm
 * @group aot
 */
final class SplFileObjectCurrentAot33521Test extends TestCase
{
    public function testJitExplodeScopesFindDelimOntoHelperFunction(): void
    {
        $src = (string) file_get_contents(__DIR__.'/../../ext/standard/JitExplode.php');
        $this->assertStringContainsString('BasicBlockHelper::scopeLoweringToFunction', $src);
        $this->assertStringContainsString('#33521', $src);
        $this->assertStringContainsString('phpc_explode_find_delim', $src);
        $ensurePos = strpos($src, 'function ensureFindDelimFunction');
        $this->assertNotFalse($ensurePos);
        $slice = substr($src, $ensurePos, 2500);
        $scopePos = strpos($slice, 'scopeLoweringToFunction');
        $findPos = strpos($slice, 'VmStringCompare::findOffset');
        $this->assertNotFalse($scopePos);
        $this->assertNotFalse($findPos);
        $this->assertLessThan(
            $findPos,
            $scopePos,
            'findOffset must run inside scopeLoweringToFunction'
        );
    }

    public function testAotMatchesZendForCurrentForeachSeek(): void
    {
        $root = dirname(__DIR__, 2);
        $repro = $root.'/test/repro/splfileobject_current_aot.php';
        $this->assertFileExists($repro);

        $zendOut = [];
        $zendRc = 0;
        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($repro).' 2>&1', $zendOut, $zendRc);
        $this->assertSame(0, $zendRc, implode("\n", $zendOut));
        $zend = implode("\n", $zendOut)."\n";
        $this->assertStringContainsString('current=[a', $zend);
        $this->assertStringContainsString('seek1=[b', $zend);
        $this->assertStringContainsString('foreach=0:[a', $zend);
        $this->assertStringContainsString('1:[b', $zend);

        if (!LlvmToolchain::hasLibrary($root)) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }

        $bin = sys_get_temp_dir().'/phpc_issue_33521_'.getmypid().'.bin';
        $compile = escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($repro).' 2>&1';
        $cwd = getcwd();
        chdir($root);
        try {
            $compileOut = [];
            $compileRc = 0;
            exec($compile, $compileOut, $compileRc);
            $this->assertSame(0, $compileRc, implode("\n", $compileOut));
            $this->assertStringNotContainsString('Module verification failed', implode("\n", $compileOut));

            $runOut = [];
            $runRc = 0;
            exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
            $this->assertSame(0, $runRc, implode("\n", $runOut));
            $aot = implode("\n", $runOut)."\n";
            $this->assertSame($zend, $aot, "AOT must match Zend\nZend:\n$zend\nAOT:\n$aot");
        } finally {
            chdir($cwd);
            @unlink($bin);
        }
    }

    public function testNoNewRuntimeC(): void
    {
        $root = dirname(__DIR__, 2);
        $this->assertFileDoesNotExist($root.'/runtime/spl_fileobject_current.c');
        $this->assertFileDoesNotExist($root.'/runtime/phpc_explode_find_delim.c');
    }
}
