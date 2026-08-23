<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * AOT: ReflectionExtension::isPersistent / isTemporary match Zend/VM (#34154).
 *
 * @see php-src ext/reflection/php_reflection.c zim_ReflectionExtension_isPersistent
 * @see php-src ext/reflection/php_reflection.c zim_ReflectionExtension_isTemporary
 * @see \PHPCompiler\JIT\Call\ReflectionExtensionIsPersistent
 * @see \PHPCompiler\JIT\Call\ReflectionExtensionIsTemporary
 *
 * @group llvm
 * @group aot
 */
final class Issue34154ReflectionExtensionIsPersistentAotTest extends TestCase
{
    private const EXPECT = 'persistent=1 temporary=0';

    public function testContextRegistersIsPersistentAndIsTemporaryProxies(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2).'/lib/JIT/Context.php'
        );
        $this->assertStringContainsString(
            "functionProxies['reflectionextension::ispersistent']",
            $source
        );
        $this->assertStringContainsString(
            "functionProxies['reflectionextension::istemporary']",
            $source
        );
        $this->assertStringContainsString('#34154', $source);
        $this->assertFileExists(
            dirname(__DIR__, 2).'/lib/JIT/Call/ReflectionExtensionIsPersistent.php'
        );
        $this->assertFileExists(
            dirname(__DIR__, 2).'/lib/JIT/Call/ReflectionExtensionIsTemporary.php'
        );
        $this->assertFileExists(
            dirname(__DIR__, 2).'/lib/JIT/Builtin/ReflectionExtensionIsPersistentRuntime.php'
        );
    }

    public function testAotIsPersistentIsTemporaryMatchZend(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_34154_reflection_extension_ispersistent_aot.php';
        $bin = sys_get_temp_dir().'/phpc_34154_gen_'.getmypid().'.bin';
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

    public function testZendIsPersistentIsTemporaryBaseline(): void
    {
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_34154_reflection_extension_ispersistent_aot.php';
        $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg($src).' 2>&1';
        exec($cmd, $out, $rc);
        $joined = trim(implode("\n", $out));
        $this->assertSame(0, $rc, $joined);
        $this->assertSame(self::EXPECT, $joined);
    }

    public function testVmIsPersistentIsTemporaryMatchZend(): void
    {
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_34154_reflection_extension_ispersistent_aot.php';
        $cmd = escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/vm.php').' '
            .escapeshellarg($src).' 2>&1';
        exec($cmd, $out, $rc);
        $joined = trim(implode("\n", $out));
        $this->assertSame(0, $rc, $joined);
        $this->assertSame(self::EXPECT, $joined);
    }
}
