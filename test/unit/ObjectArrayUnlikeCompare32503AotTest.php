<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * AOT: object/array vs scalar ordered compare is Zend zend_compare (#32503 leftover of #32477/#32346).
 *
 * @see php-src Zend/zend_operators.c compare_function
 *
 * @group llvm
 * @group aot
 */
final class ObjectArrayUnlikeCompare32503AotTest extends TestCase
{
    private const EXPECT = "ngt\n0\nrngt\nagt\n1\ncge\n";

    public function testVmObjectArrayUnlikeCompareMatchesZend(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(
            dirname(__DIR__).'/repro/issue_object_array_unlike_compare.php'
        );
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'issue_object_array_unlike_compare.php'));
        $out = self::stripNotices((string) ob_get_clean());
        $this->assertSame(self::EXPECT, $out);
    }

    public function testAotObjectArrayUnlikeCompareMatchesZend(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_object_array_unlike_compare.php';
        $bin = sys_get_temp_dir().'/phpc_issue_32503_'.getmypid().'.bin';
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
