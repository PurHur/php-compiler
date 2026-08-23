<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * AOT/VM: ReflectionClass::getMethods matches Zend (#34107).
 *
 * @see php-src ext/reflection/php_reflection.c zim_ReflectionClass_getMethods
 * @see \PHPCompiler\JIT\Call\ReflectionClassGetMethods
 *
 * @group llvm
 * @group aot
 */
final class Issue34107ReflectionClassGetMethodsAotTest extends TestCase
{
    /** ChildGm: skip parent-private p; declaring scope on inherited (#7191 / #22582). */
    private const EXPECT_CHILD = 'q@ChildGm,r@BaseGm,s@BaseGm,t@ChildGm';

    private const EXPECT_CHILD_NAMES = 'q,r,s,t';

    private const EXPECT_SIMPLE = 'm';

    public function testContextRegistersGetMethodsProxy(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2).'/lib/JIT/Context.php'
        );
        $this->assertStringContainsString(
            "functionProxies['reflectionclass::getmethods']",
            $source
        );
        $this->assertStringContainsString('#34107', $source);
        $this->assertFileExists(
            dirname(__DIR__, 2).'/lib/JIT/Call/ReflectionClassGetMethods.php'
        );
    }

    public function testAotGetMethodsMatchesZend(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_34107_reflection_get_methods_aot.php';
        $bin = sys_get_temp_dir().'/phpc_34107_gm_'.getmypid().'.bin';
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
                $this->assertSame(
                    self::EXPECT_CHILD."\n".self::EXPECT_CHILD_NAMES."\n".self::EXPECT_SIMPLE,
                    trim($joined)
                );
            }
        } finally {
            @unlink($bin);
        }
    }

    public function testZendGetMethodsBaseline(): void
    {
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_34107_reflection_get_methods_aot.php';
        $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg($src).' 2>&1';
        exec($cmd, $out, $rc);
        $joined = trim(implode("\n", $out));
        $this->assertSame(0, $rc, $joined);
        $this->assertSame(
            self::EXPECT_CHILD."\n".self::EXPECT_CHILD_NAMES."\n".self::EXPECT_SIMPLE,
            $joined
        );
    }

    public function testVmGetMethodsMatchesZend(): void
    {
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_34107_reflection_get_methods_aot.php';
        $cmd = escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/vm.php').' '
            .escapeshellarg($src).' 2>&1';
        exec($cmd, $out, $rc);
        $joined = trim(implode("\n", $out));
        $this->assertSame(0, $rc, $joined);
        $this->assertSame(
            self::EXPECT_CHILD."\n".self::EXPECT_CHILD_NAMES."\n".self::EXPECT_SIMPLE,
            $joined
        );
        $this->assertStringNotContainsString('p@', $joined);
    }
}
