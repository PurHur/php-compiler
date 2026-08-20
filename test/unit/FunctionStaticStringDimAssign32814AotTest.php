<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: function-static string dim assign must not clobber / no-op (#32814).
 *
 * DECLARE retypes string-default function-statics to CFG string so FETCH_DIM_W
 * takes {@see ValueBoxDimWrite} (same pattern as array defaults → TYPE_ARRAY in #32806).
 * {@see Variable::fromValueOp} types i8* char pointers as TYPE_STRING so
 * {@see StringOffsetHelper::isWritableCharOffsetLvalue} accepts them when the dim-fetch
 * result operand is CFG-unknown.
 *
 * @see php-src Zend/zend_execute.c zend_assign_to_string_offset
 *
 * @group llvm
 * @group aot
 */
final class FunctionStaticStringDimAssign32814AotTest extends TestCase
{
    private const EXPECTED = "aZc\naZc\n";

    public function testVmFunctionStaticStringDimAssign(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(
            dirname(__DIR__).'/repro/issue_32814_function_static_string_dim_assign.php'
        );
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'issue_32814_function_static_string_dim_assign.php'));
        $out = (string) ob_get_clean();
        $this->assertSame(self::EXPECTED, $out);
    }

    public function testAotFunctionStaticStringDimAssign(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_32814_function_static_string_dim_assign.php';
        $bin = sys_get_temp_dir().'/phpc_issue_32814_fssda_'.getmypid().'.bin';
        $compile = escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        $this->assertFileExists($bin);
        try {
            $runOut = [];
            exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
            $this->assertSame(0, $runRc, implode("\n", $runOut));
            $this->assertSame(self::EXPECTED, implode("\n", $runOut)."\n");
        } finally {
            @unlink($bin);
        }
    }
}
