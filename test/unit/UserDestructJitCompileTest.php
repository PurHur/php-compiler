<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * User __destruct() VM + AOT compile/execute (#4013, #4096).
 *
 * JIT {@see JIT\Context::compileCommon()} module-verify on tiny scripts is not a
 * reliable gate here (parentless IR under the MCJIT path); AOT via bin/compile.php
 * is the release path and matches Zend.
 *
 * @group llvm
 * @group aot
 *
 * @see https://github.com/php/php-src/blob/master/Zend/zend_objects.c zend_objects_destroy_object
 */
final class UserDestructJitCompileTest extends TestCase
{
    private const EXPECT = "dtor\nafter\n";

    public function testVmUserDestructOnUnsetMatchesZend(): void
    {
        $code = file_get_contents(dirname(__DIR__).'/repro/aot_user_destruct_unset.php');
        $this->assertNotFalse($code);
        ob_start();
        (new Runtime())->run((new Runtime())->parseAndCompile($code, 'aot_user_destruct_unset.php'));
        $out = (string) ob_get_clean();
        $this->assertSame(self::EXPECT, $out);
    }

    public function testAotUserDestructCompileAndRunMatchesVm(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/aot_user_destruct_unset.php';
        $bin = sys_get_temp_dir().'/phpc_aot_user_destruct_'.getmypid().'.bin';
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
            $this->assertSame(self::EXPECT, implode("\n", $runOut)."\n");
        } finally {
            @unlink($bin);
        }
    }
}
