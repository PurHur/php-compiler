<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * AOT: ReflectionClass kind queries match Zend (#34032).
 *
 * @see php-src ext/reflection/php_reflection.c zim_ReflectionClass_isInterface|isAbstract|isTrait|isEnum
 * @see \PHPCompiler\JIT\Call\ReflectionClassKindQuery
 *
 * @group llvm
 * @group aot
 */
final class Issue34032ReflectionClassKindQueriesAotTest extends TestCase
{
    private const EXPECT = "I=1,0,0,0\nA=0,1,0,0\nT=0,0,1,0\nE=0,0,0,1\nC=0,0,0,0";

    public function testContextRegistersKindQueryProxies(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2).'/lib/JIT/Context.php'
        );
        foreach (['isinterface', 'isabstract', 'istrait', 'isenum'] as $m) {
            $this->assertStringContainsString(
                "functionProxies['reflectionclass::".$m."']",
                $source
            );
        }
        $this->assertStringContainsString('#34032', $source);
    }

    public function testAotKindQueriesMatchZend(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_34032_reflection_kind_queries_aot.php';
        $bin = sys_get_temp_dir().'/phpc_34032_kind_'.getmypid().'.bin';
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

    public function testVmKindQueriesMatchZend(): void
    {
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_34032_reflection_kind_queries_aot.php';
        $cmd = escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/vm.php').' '
            .escapeshellarg($src).' 2>&1';
        exec($cmd, $out, $rc);
        $joined = implode("\n", $out);
        $this->assertSame(0, $rc, $joined);
        $this->assertSame(self::EXPECT, trim($joined));
    }
}
