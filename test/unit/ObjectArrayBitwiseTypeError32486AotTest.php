<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * AOT: native-object/array bitwise &|^~ and <<>> TypeError (#32486).
 *
 * @see php-src Zend/zend_operators.c bitwise_*_function / shift_*_function / bitwise_not_function
 *
 * @group llvm
 * @group aot
 */
final class ObjectArrayBitwiseTypeError32486AotTest extends TestCase
{
    private const EXPECT = "Unsupported operand types: stdClass & int\n"
        ."Unsupported operand types: stdClass | int\n"
        ."Unsupported operand types: stdClass ^ int\n"
        ."Unsupported operand types: stdClass << int\n"
        ."Unsupported operand types: stdClass >> int\n"
        ."Cannot perform bitwise not on stdClass\n"
        ."Unsupported operand types: array & int\n"
        ."Unsupported operand types: array << int\n"
        ."Cannot perform bitwise not on array\n";

    public function testVmObjectArrayBitwiseTypeErrorMatchesZend(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(
            dirname(__DIR__).'/repro/issue_object_array_bitwise_typeerror.php'
        );
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'issue_object_array_bitwise_typeerror.php'));
        $out = (string) ob_get_clean();
        $this->assertSame(self::EXPECT, $out);
    }

    public function testAotObjectArrayBitwiseTypeErrorMatchesZend(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_object_array_bitwise_typeerror.php';
        $bin = sys_get_temp_dir().'/phpc_issue_32486_'.getmypid().'.bin';
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
