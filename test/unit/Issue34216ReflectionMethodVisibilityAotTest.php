<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * AOT: ReflectionMethod isPublic / isStatic match Zend (#34216).
 *
 * @see php-src ext/reflection/php_reflection.c zim_ReflectionMethod_isPublic
 * @see \PHPCompiler\JIT\Call\ReflectionMethodVisibilityQuery
 *
 * @group llvm
 * @group aot
 */
final class Issue34216ReflectionMethodVisibilityAotTest extends TestCase
{
    private const EXPECT = "m_pub=1 m_static=0\ns_pub=1 s_static=1\np_pub=0 p_static=0";

    public function testContextRegistersVisibilityProxies(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2).'/lib/JIT/Context.php'
        );
        foreach (['ispublic', 'isstatic'] as $m) {
            $this->assertStringContainsString(
                "functionProxies['reflectionmethod::".$m."']",
                $source
            );
        }
        $this->assertStringContainsString('#34216', $source);
    }

    public function testAotIsPublicIsStaticMatchZend(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_34216_reflection_method_visibility_aot.php';
        $bin = sys_get_temp_dir().'/phpc_34216_vis_'.getmypid().'.bin';
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

    public function testVmIsPublicIsStaticMatchZend(): void
    {
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_34216_reflection_method_visibility_aot.php';
        $cmd = escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/vm.php').' '
            .escapeshellarg($src).' 2>&1';
        exec($cmd, $out, $rc);
        $joined = implode("\n", $out);
        $this->assertSame(0, $rc, $joined);
        $this->assertSame(self::EXPECT, trim($joined));
    }
}
