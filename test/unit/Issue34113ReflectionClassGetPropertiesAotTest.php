<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * AOT/VM: ReflectionClass::getProperties matches Zend (#34113).
 *
 * @see php-src ext/reflection/php_reflection.c zim_ReflectionClass_getProperties
 * @see \PHPCompiler\JIT\Call\ReflectionClassGetProperties
 *
 * @group llvm
 * @group aot
 */
final class Issue34113ReflectionClassGetPropertiesAotTest extends TestCase
{
    /** ChildGp: skip parent-private $p; declaring scope on inherited. */
    private const EXPECT_ALL = 'q@ChildGp,r@BaseGp,s@BaseGp,sq@ChildGp,ss@BaseGp,t@ChildGp';

    private const EXPECT_NULL = 'q,r,s,sq,ss,t';

    private const EXPECT_PUBLIC = 'q,s,sq,ss';

    public function testContextRegistersGetPropertiesProxy(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2).'/lib/JIT/Context.php'
        );
        $this->assertStringContainsString(
            "functionProxies['reflectionclass::getproperties']",
            $source
        );
        $this->assertStringContainsString('#34113', $source);
        $this->assertFileExists(
            dirname(__DIR__, 2).'/lib/JIT/Call/ReflectionClassGetProperties.php'
        );
    }

    public function testAotGetPropertiesMatchesZend(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_34113_reflection_get_properties_aot.php';
        $bin = sys_get_temp_dir().'/phpc_34113_gp_'.getmypid().'.bin';
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
                    self::EXPECT_ALL."\n".self::EXPECT_NULL."\n".self::EXPECT_PUBLIC,
                    trim($joined)
                );
            }
        } finally {
            @unlink($bin);
        }
    }

    public function testZendGetPropertiesBaseline(): void
    {
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_34113_reflection_get_properties_aot.php';
        $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg($src).' 2>&1';
        exec($cmd, $out, $rc);
        $joined = trim(implode("\n", $out));
        $this->assertSame(0, $rc, $joined);
        $this->assertSame(
            self::EXPECT_ALL."\n".self::EXPECT_NULL."\n".self::EXPECT_PUBLIC,
            $joined
        );
    }

    public function testVmGetPropertiesMatchesZend(): void
    {
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_34113_reflection_get_properties_aot.php';
        $cmd = escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/vm.php').' '
            .escapeshellarg($src).' 2>&1';
        exec($cmd, $out, $rc);
        $joined = trim(implode("\n", $out));
        $this->assertSame(0, $rc, $joined);
        $this->assertSame(
            self::EXPECT_ALL."\n".self::EXPECT_NULL."\n".self::EXPECT_PUBLIC,
            $joined
        );
        $this->assertStringNotContainsString('p@', $joined);
    }
}
