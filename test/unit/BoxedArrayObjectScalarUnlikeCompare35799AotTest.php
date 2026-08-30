<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * AOT: assigned array/object vs native int ordered compare is Zend zend_compare (#35799).
 *
 * @see php-src Zend/zend_operators.c compare_function
 *
 * @group llvm
 * @group aot
 */
final class BoxedArrayObjectScalarUnlikeCompare35799AotTest extends TestCase
{
    private const EXPECT = "1\nagt\n-1\n1\n0\nnogt\n0\n";

    public function testVmBoxedArrayObjectScalarUnlikeCompareMatchesZend(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(
            dirname(__DIR__).'/repro/issue_boxed_array_object_scalar_unlike_compare.php'
        );
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'issue_boxed_array_object_scalar_unlike_compare.php'));
        $out = self::stripNotices((string) ob_get_clean());
        $this->assertSame(self::EXPECT, $out);
    }

    public function testAotBoxedArrayObjectScalarUnlikeCompareMatchesZend(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_boxed_array_object_scalar_unlike_compare.php';
        $bin = sys_get_temp_dir().'/phpc_issue_35799_'.getmypid().'.bin';
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
        return (string) preg_replace('/^(PHP )?Notice:.*\n/m', '', $out);
    }
}
