<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Instance method (Object $x, ...$rest) must keep LLVM Variable types aligned with
 * CFG params after the implicit $this slot — otherwise writeHashtable/setObjectAt
 * fail module verify (#24429, ext/ds DsFactoryFunction::call under SPINE_CHUNK).
 *
 * @group llvm
 * @group aot
 */
final class SpineChunkMethodObjectThenVariadic24429AotTest extends TestCase
{
    public function testAotCompileAndRunObjectThenVariadicMethod(): void
    {
        require_once dirname(__DIR__).'/LlvmToolchain.php';
        $root = dirname(__DIR__, 2);
        LlvmToolchain::applyCurrentProcessEnv($root);
        if (!LlvmToolchain::isReady($root)) {
            $this->markTestSkipped(LlvmToolchain::readyFailureReason() ?? 'LLVM unavailable');
        }

        $src = $root.'/test/repro/issue_24429_method_object_then_variadic.php';
        $bin = sys_get_temp_dir().'/phpc_24429_variadic_'.getmypid().'.bin';
        $compile = 'env PHP_COMPILER_HELPER_RUNTIME_O=0 '.escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $out, $rc);
        $joined = implode("\n", $out);
        $this->assertSame(0, $rc, $joined);
        $this->assertStringNotContainsString('Module verification failed', $joined);
        $this->assertFileExists($bin);
        try {
            exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
            $this->assertSame(0, $runRc, implode("\n", $runOut));
            $this->assertStringContainsString('|2', implode("\n", $runOut)."\n");
        } finally {
            @unlink($bin);
        }
    }
}
