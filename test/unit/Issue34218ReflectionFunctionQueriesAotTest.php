<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * AOT: ReflectionFunction getNumberOfParameters / isUserDefined / isInternal match Zend (#34218).
 *
 * @see php-src ext/reflection/php_reflection.c zim_ReflectionFunctionAbstract_*
 * @see \PHPCompiler\JIT\Call\ReflectionFunctionGetNumberOfParameters
 *
 * @group llvm
 * @group aot
 */
final class Issue34218ReflectionFunctionQueriesAotTest extends TestCase
{
    private const EXPECT = "n=1\nuser=1\ninternal=0\nstrlen_n=1\nstrlen_user=0\nstrlen_internal=1";

    public function testContextRegistersParamCountUserInternalProxies(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2).'/lib/JIT/Context.php'
        );
        foreach (['getnumberofparameters', 'isuserdefined', 'isinternal'] as $m) {
            $this->assertStringContainsString(
                "functionProxies['reflectionfunction::".$m."']",
                $source
            );
        }
        $this->assertStringContainsString('#34218', $source);
    }

    public function testAotQueriesMatchZend(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_34218_reflection_function_queries_aot.php';
        $bin = sys_get_temp_dir().'/phpc_34218_rfq_'.getmypid().'.bin';
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

    public function testVmQueriesMatchZend(): void
    {
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_34218_reflection_function_queries_aot.php';
        $cmd = escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/vm.php').' '
            .escapeshellarg($src).' 2>&1';
        exec($cmd, $out, $rc);
        $joined = implode("\n", $out);
        $this->assertSame(0, $rc, $joined);
        $this->assertSame(self::EXPECT, trim($joined));
    }
}
