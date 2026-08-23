<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * AOT: `$obj->$m()` with compile-time-foldable method name (#34084).
 *
 * @see php-src Zend/zend_execute.c ZEND_INIT_METHOD_CALL
 *
 * @group llvm
 * @group aot
 */
final class Issue34084DynamicMethodNameAotTest extends TestCase
{
    private const EXPECT = "7\nok";

    public function testMethodCallInitFoldsNonLiteralName(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2).'/lib/JIT.php');
        $this->assertStringContainsString('#34084', $source);
        $this->assertStringContainsString(
            'foldCompileTimeStringFromSlot($block, $slot, $nameVar)',
            $source
        );
        // Must not hard-require Literal before resolving compile-time string.
        $this->assertDoesNotMatchRegularExpression(
            '/TYPE_METHODCALL_INIT:\s*\$receiverOp[^\n]+\s*\$nameOp[^\n]+\s*assert\(\$nameOp instanceof Operand\\\\Literal\);/s',
            $source
        );
    }

    public function testAotDynamicMethodNameMatchesZend(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_34084_dynamic_method_name_aot.php';
        $bin = sys_get_temp_dir().'/phpc_34084_dyn_'.getmypid().'.bin';
        $compile = 'env PHP_COMPILER_HELPER_RUNTIME_O=0 '
            .escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        try {
            for ($i = 0; $i < 3; ++$i) {
                $runOut = [];
                exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
                $joined = implode("\n", $runOut);
                $this->assertSame(0, $runRc, 'run '.$i.': '.$joined);
                $this->assertSame(self::EXPECT, trim($joined));
            }
        } finally {
            @unlink($bin);
        }
    }

    public function testVmDynamicMethodNameMatchesZend(): void
    {
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_34084_dynamic_method_name_aot.php';
        $cmd = escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/vm.php').' '
            .escapeshellarg($src).' 2>&1';
        exec($cmd, $out, $rc);
        $joined = implode("\n", $out);
        $this->assertSame(0, $rc, $joined);
        $this->assertSame(self::EXPECT, trim($joined));
    }
}
