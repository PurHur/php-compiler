<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: file(data://) matches Zend RFC2397 line split (#34772 / peer #34731).
 *
 * @see php-src ext/standard/php_data_wrapper.c
 * @see php-src ext/standard/file.c PHP_FUNCTION(file)
 *
 * @group llvm
 * @group aot
 */
final class FileDataUri34772AotTest extends TestCase
{
    // Zend print_r keeps trailing newlines inside line strings (except FILE_IGNORE_NEW_LINES).
    private const EXPECT = "plain:\nArray\n(\n    [0] => a\n\n    [1] => b\n)\nbase64:\nArray\n(\n    [0] => a\n\n    [1] => b\n)\nfs:\nArray\n(\n    [0] => x\n\n    [1] => y\n\n)\nignore_nl:\nArray\n(\n    [0] => a\n    [1] => b\n)\n";

    public function testVmFileDataUri(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(dirname(__DIR__).'/repro/issue_34772_file_data_uri_aot.php');
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'issue_34772_file_data_uri_aot.php'));
        $out = (string) ob_get_clean();
        $this->assertSame(self::EXPECT, $out);
    }

    public function testAotFileDataUri(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_34772_file_data_uri_aot.php';
        $bin = sys_get_temp_dir().'/phpc_aot_file_data_34772_'.getmypid().'.bin';
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

    public function testJitFileEnsuresFileGetContentsAbi(): void
    {
        $src = (string) file_get_contents(__DIR__.'/../../ext/standard/JitFile.php');
        $this->assertStringContainsString(
            'StringFileGetContents::ensureLinked',
            $src,
            'JitFile must link __compiler_file_get_contents before lookup (#34772)'
        );
        $this->assertStringNotContainsString(
            'JitStat::pathExists($context',
            $src,
            'pathExists rejects data:// — file() must read via file_get_contents (#34772)'
        );
    }
}
