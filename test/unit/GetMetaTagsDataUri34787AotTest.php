<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT/VM: get_meta_tags(data://) matches Zend (#34787 / peer #34731).
 *
 * @see php-src ext/standard/php_meta_tags.c
 * @see php-src ext/standard/php_data_wrapper.c
 *
 * @group llvm
 * @group aot
 */
final class GetMetaTagsDataUri34787AotTest extends TestCase
{
    // var_export spacing matches PHP 8.2 Runtime / AOT.
    private const EXPECT = "plain:array (\n  'a' => 'b',\n)\nbase64:array (\n  'c' => 'd',\n)\nfs:array (\n  'x' => 'y',\n)\n";

    public function testHelperIsParseOnlyNoLibcOpen(): void
    {
        $src = (string) file_get_contents(__DIR__.'/../../ext/standard/MetaTagsJitHelper.php');
        $this->assertStringContainsString('#34787', $src);
        $this->assertStringContainsString('parseHtmlToNativeHt', $src);
        $this->assertStringNotContainsString(
            '@file_get_contents',
            $src,
            'parse-only helper — reads via bridge __compiler_file_get_contents (#34787)'
        );
    }

    public function testRuntimeBridgeReadsViaFileGetContents(): void
    {
        $src = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/MetaTagsRuntime.php');
        $this->assertStringContainsString('StringFileGetContents::ensureLinked', $src);
        $this->assertStringContainsString('__compiler_file_get_contents', $src);
        $this->assertStringContainsString('parseHtmlToNativeHt', $src);
    }

    public function testVmGetMetaTagsDataUri(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(dirname(__DIR__).'/repro/issue_34787_get_meta_tags_data_uri_aot.php');
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'issue_34787_get_meta_tags_data_uri_aot.php'));
        $out = (string) ob_get_clean();
        $this->assertSame(self::EXPECT, $out);
    }

    public function testAotGetMetaTagsDataUri(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_34787_get_meta_tags_data_uri_aot.php';
        $bin = sys_get_temp_dir().'/phpc_aot_meta_34787_'.getmypid().'.bin';
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
