<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: preg_replace string-local subject must not take invokeArray (#35059 / peer #23912).
 *
 * @see php-src ext/pcre/php_pcre.c
 *
 * @group llvm
 * @group aot
 */
final class PregReplaceTypeValueSubject35059AotTest extends TestCase
{
    private const EXPECT = "'123'\n'Y2Fmw6k='\n'y'\n";

    public function testHelperSourceUsesTypeValueStringishGuard(): void
    {
        $src = (string) file_get_contents(__DIR__.'/../../ext/standard/preg_replace.php');
        $this->assertStringContainsString('#35059', $src);
        $this->assertStringContainsString('JitStrReplaceSubject::isKnownArray', $src);
        $this->assertStringContainsString('subjectIsStringish', $src);
    }

    public function testVmPregReplaceTypeValueSubject(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(dirname(__DIR__).'/repro/aot_preg_replace_type_value_subject.php');
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'aot_preg_replace_type_value_subject.php'));
        $out = (string) ob_get_clean();
        $this->assertSame(self::EXPECT, $out);
    }

    public function testAotPregReplaceTypeValueSubject(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/aot_preg_replace_type_value_subject.php';
        $bin = sys_get_temp_dir().'/phpc_aot_preg_35059_'.getmypid().'.bin';
        $compile = 'env PHP_COMPILER_HELPER_RUNTIME_O=0 '.escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        $this->assertFileExists($bin);
        try {
            $runOut = [];
            exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
            $this->assertSame(0, $runRc, implode("\n", $runOut));
            $this->assertSame(self::EXPECT, implode("\n", $runOut)."\n");
        } finally {
            @unlink($bin);
        }
    }
}
