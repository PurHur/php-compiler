<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * AOT: object vs string/null ordered compare is Zend zend_compare (#32514 leftover of #32503).
 *
 * @see php-src Zend/zend_operators.c compare_function
 *
 * @group llvm
 * @group aot
 */
final class ObjectStringNullUnlikeCompare32514AotTest extends TestCase
{
    private const EXPECT = "s_gt\n1\n1\nrs_ngt\nn_gt\n1\nrn_ngt\n1\n0\n-1\n";

    public function testVmObjectStringNullUnlikeCompareMatchesZend(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(
            dirname(__DIR__).'/repro/issue_object_string_null_unlike_compare.php'
        );
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'issue_object_string_null_unlike_compare.php'));
        $out = self::stripNotices((string) ob_get_clean());
        $this->assertSame(self::EXPECT, $out);
    }

    public function testAotObjectStringNullUnlikeCompareMatchesZend(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_object_string_null_unlike_compare.php';
        $bin = sys_get_temp_dir().'/phpc_issue_32514_'.getmypid().'.bin';
        $compile = 'env PHP_COMPILER_HELPER_RUNTIME_O=0 '.escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        $this->assertFileExists($bin);
        try {
            $runOut = [];
            exec(escapeshellarg($bin).' 2>/dev/null', $runOut, $runRc);
            $this->assertSame(0, $runRc, implode("\n", $runOut));
            $this->assertSame(self::EXPECT, implode("\n", $runOut)."\n");
        } finally {
            @unlink($bin);
        }
    }

    private static function stripNotices(string $out): string
    {
        $out = (string) preg_replace('/^(PHP )?Notice:.*\n/m', '', $out);

        return (string) preg_replace('/^PHP Notice:.*\n/m', '', $out);
    }
}
