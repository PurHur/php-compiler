<?php

declare(strict_types=1);

namespace PHPCompiler\Test;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * Promoted ctor parameter defaults with `new` expressions (#6652, #3391).
 *
 * php-src: Zend/zend_compile.c default_value, zend_objects.c property init.
 */
final class PromotedParamNewDefault6652Test extends TestCase
{
    private const EXPECT = "builtin\nexplicit\n1\n";

    public function testVmPromotedParamNewDefaultMatchesZend(): void
    {
        $code = file_get_contents(dirname(__DIR__).'/repro/promoted_new_aot.php');
        $this->assertNotFalse($code);
        ob_start();
        (new Runtime())->run((new Runtime())->parseAndCompile($code, 'promoted_new_aot.php'));
        $out = (string) ob_get_clean();
        $this->assertSame(self::EXPECT, $out);
    }

    public function testAotPromotedParamNewDefaultMatchesVm(): void
    {
        $root = dirname(__DIR__, 2);
        if (!\PHPCompiler\LlvmToolchain::hasLibrary($root)) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $src = $root.'/test/repro/promoted_new_aot.php';
        $bin = sys_get_temp_dir().'/phpc_promoted_new_6652_'.getmypid().'.bin';
        $compile = escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        $this->assertFileExists($bin);
        exec(escapeshellarg($bin), $runOut, $runRc);
        $this->assertSame(0, $runRc);
        $this->assertSame(self::EXPECT, implode("\n", $runOut).( [] === $runOut ? '' : "\n"));
        @unlink($bin);
    }
}
