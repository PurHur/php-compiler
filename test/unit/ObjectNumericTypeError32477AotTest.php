<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * AOT: native-object unary +/- and object⊙int TypeError (#32477).
 *
 * @see php-src Zend/zend_operators.c ZEND_TRY_UNARY_OBJECT_OPERATION / add_function
 *
 * @group llvm
 * @group aot
 */
final class ObjectNumericTypeError32477AotTest extends TestCase
{
    private const EXPECT = "Unsupported operand types: stdClass\n"
        ."Unsupported operand types: stdClass\n"
        ."Unsupported operand types: stdClass\n"
        ."Unsupported operand types: stdClass + int\n"
        ."Unsupported operand types: int + stdClass\n"
        ."Unsupported operand types: C32477 * int\n";

    public function testVmObjectNumericTypeErrorMatchesZend(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(
            dirname(__DIR__).'/repro/issue_object_numeric_typeerror.php'
        );
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'issue_object_numeric_typeerror.php'));
        $out = (string) ob_get_clean();
        $this->assertSame(self::EXPECT, $out);
    }

    public function testAotObjectNumericTypeErrorMatchesZend(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_object_numeric_typeerror.php';
        $bin = sys_get_temp_dir().'/phpc_issue_32477_'.getmypid().'.bin';
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
