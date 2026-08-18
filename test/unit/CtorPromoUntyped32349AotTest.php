<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: untyped/string/mixed constructor property promotion (#32349).
 *
 * php-src: Zend/zend_compile.c — ZEND_ACC_PROMOTTED emits ZEND_ASSIGN_OBJ in __construct.
 *
 * @group llvm
 * @group aot
 */
final class CtorPromoUntyped32349AotTest extends TestCase
{
    private const EXPECTED = "1|7\n3\nab\n14\n1\n2|9\n";

    public function testVmCtorPromoUntypedStringMixed(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(
            dirname(__DIR__).'/repro/issue_ctor_promo_untyped_aot.php'
        );
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'issue_ctor_promo_untyped_aot.php'));
        $out = (string) ob_get_clean();
        $this->assertSame(self::EXPECTED, $out);
    }

    public function testAotCtorPromoUntypedStringMixed(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_ctor_promo_untyped_aot.php';
        $bin = sys_get_temp_dir().'/phpc_issue_32349_promo_'.getmypid().'.bin';
        $compile = 'PHP_COMPILER_HELPER_RUNTIME_O=0 '.escapeshellarg(PHP_BINARY).' '
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

    public function testWriteFetchSkipsValueSlotLoad(): void
    {
        $slot = (string) file_get_contents(
            __DIR__.'/../../lib/JIT/Builtin/Type/ObjectInstancePropertyLlvm.php'
        );
        $this->assertStringContainsString('#32349', $slot);
        $this->assertStringContainsString('bool $forWrite = false', $slot);
        $this->assertStringContainsString('if ($forWrite)', $slot);
        $helper = (string) file_get_contents(__DIR__.'/../../lib/JIT/BasicBlockHelper.php');
        $this->assertStringContainsString('unsealAndContinue', $helper);
        $this->assertStringContainsString('continueAfterDefiningValue', $helper);
    }
}
