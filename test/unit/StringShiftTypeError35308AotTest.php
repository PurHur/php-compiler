<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: non-numeric string << / >> prints Zend TypeError (not SIGABRT) (#35308).
 *
 * @see php-src Zend/zend_operators.c shift_left_function / shift_right_function
 *
 * @group llvm
 * @group aot
 */
final class StringShiftTypeError35308AotTest extends TestCase
{
    private const EXPECT = <<<'TXT'
string <<: TypeError:Unsupported operand types: string << int
string >>: TypeError:Unsupported operand types: string >> int
var <<: TypeError:Unsupported operand types: string << int
numeric: 4

TXT;

    public function testShiftGuardUsesExceptionBridge(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/VM/VmShiftOperandGuard.php');
        $this->assertStringContainsString('ExceptionBridge::emitTypeErrorAndAbort', $source);
        $this->assertStringContainsString('#35308', $source);
        $this->assertStringNotContainsString(
            "lookupFunction('abort')",
            $source,
            'raw abort() leaves standalone AOT silent SIGABRT (#35308)'
        );

        $helper = (string) file_get_contents(__DIR__.'/../../lib/JIT/Helper.php');
        $this->assertStringContainsString('#35308', $helper);
        $fn = strpos($helper, 'JitShiftOperandGuard::guardOperands');
        $this->assertNotFalse($fn);
        $chunk = substr($helper, $fn, 280);
        $this->assertStringContainsString('nativeLongResultVariable', $chunk);
    }

    public function testVmStringShiftTypeErrorMatchesZend(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(
            dirname(__DIR__).'/repro/issue_35308_string_shift_typeerror.php'
        );
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'issue_35308_string_shift_typeerror.php'));
        $out = (string) ob_get_clean();
        $this->assertSame(self::EXPECT, $out);
    }

    /**
     * @group llvm
     * @group aot
     */
    public function testAotStringShiftTypeErrorMatchesZend(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_35308_string_shift_typeerror.php';
        $bin = sys_get_temp_dir().'/phpc_issue_35308_'.getmypid().'.bin';
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
