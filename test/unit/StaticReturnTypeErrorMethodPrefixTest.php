<?php

declare(strict_types=1);

namespace PHPCompiler\test\unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * @covers issue #29913 — `: static` return TypeError includes Class::method(): prefix
 */
final class StaticReturnTypeErrorMethodPrefixTest extends TestCase
{
    private const EXPECT = "msg:B::make(): Return value must be of type B, A returned\n";

    public function testStaticReturnTypeErrorIncludesMethodPrefix(): void
    {
        $code = file_get_contents(
            dirname(__DIR__).'/repro/maintainer_gap_static_return_typeerror_omits_method.php'
        );
        self::assertNotFalse($code);
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'issue_29913.php');
        ob_start();
        $runtime->run($block);
        $out = ob_get_clean();
        self::assertSame(self::EXPECT, $out);
    }

    public function testAotStaticReturnTypeErrorNamesGivenClass(): void
    {
        if (!\PHPCompiler\LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            self::markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/maintainer_gap_static_return_typeerror_omits_method.php';
        $bin = sys_get_temp_dir().'/phpc_issue_29913_static_'.getmypid().'.bin';
        $compile = 'env PHP_COMPILER_HELPER_RUNTIME_O=0 '.escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        self::assertSame(0, $compileRc, implode("\n", $compileOut));
        self::assertFileExists($bin);
        try {
            $runOut = [];
            exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
            self::assertSame(0, $runRc, implode("\n", $runOut));
            self::assertSame(self::EXPECT, implode("\n", $runOut)."\n");
        } finally {
            @unlink($bin);
        }
    }
}
