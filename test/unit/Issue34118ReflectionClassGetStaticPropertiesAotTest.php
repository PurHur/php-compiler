<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * AOT/VM: ReflectionClass::getStaticProperties matches Zend (#34118).
 *
 * @see php-src ext/reflection/php_reflection.c zim_ReflectionClass_getStaticProperties
 * @see \PHPCompiler\JIT\Call\ReflectionClassGetStaticProperties
 *
 * @group llvm
 * @group aot
 */
final class Issue34118ReflectionClassGetStaticPropertiesAotTest extends TestCase
{
    /** ChildGsp — parent private $p omitted; instance props omitted. */
    private const EXPECT_CHILD = '{"q":2,"r":3,"s":4,"ss":40,"t":5,"u":null}';

    /** SimpleGsp — statics only. */
    private const EXPECT_SIMPLE = '{"a":1,"b":"x"}';

    public function testContextRegistersGetStaticPropertiesProxy(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2).'/lib/JIT/Context.php'
        );
        $this->assertStringContainsString(
            "functionProxies['reflectionclass::getstaticproperties']",
            $source
        );
        $this->assertStringContainsString('#34118', $source);
        $this->assertFileExists(
            dirname(__DIR__, 2).'/lib/JIT/Call/ReflectionClassGetStaticProperties.php'
        );
    }

    public function testAotGetStaticPropertiesMatchesZend(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_34118_reflection_get_static_properties_aot.php';
        $bin = sys_get_temp_dir().'/phpc_34118_gsp_'.getmypid().'.bin';
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
                    self::EXPECT_CHILD."\n".self::EXPECT_SIMPLE,
                    trim($joined)
                );
            }
        } finally {
            @unlink($bin);
        }
    }

    public function testZendGetStaticPropertiesBaseline(): void
    {
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_34118_reflection_get_static_properties_aot.php';
        $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg($src).' 2>&1';
        exec($cmd, $out, $rc);
        $joined = trim(implode("\n", $out));
        $this->assertSame(0, $rc, $joined);
        $this->assertSame(self::EXPECT_CHILD."\n".self::EXPECT_SIMPLE, $joined);
    }

    public function testVmGetStaticPropertiesMatchesZend(): void
    {
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_34118_reflection_get_static_properties_aot.php';
        $cmd = escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/vm.php').' '
            .escapeshellarg($src).' 2>&1';
        exec($cmd, $out, $rc);
        $joined = trim(implode("\n", $out));
        $this->assertSame(0, $rc, $joined);
        $this->assertSame(self::EXPECT_CHILD."\n".self::EXPECT_SIMPLE, $joined);
        $this->assertStringNotContainsString('"p":', $joined);
        $this->assertStringNotContainsString('"inst":', $joined);
    }
}
