<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * AOT: ReflectionClass::getReflectionConstants matches Zend (#34119).
 *
 * @see php-src ext/reflection/php_reflection.c zim_ReflectionClass_getReflectionConstants
 * @see \PHPCompiler\JIT\Call\ReflectionClassGetReflectionConstants
 *
 * @group llvm
 * @group aot
 */
final class Issue34119ReflectionClassGetReflectionConstantsAotTest extends TestCase
{
    /** parent PRIV omitted; OWN+PROT+PUB; sorted */
    private const EXPECT = 'OWN,PROT,PUB|OWN,PUB|OWN,PROT,PUB';

    public function testContextRegistersGetReflectionConstantsProxy(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2).'/lib/JIT/Context.php'
        );
        $this->assertStringContainsString(
            "functionProxies['reflectionclass::getreflectionconstants']",
            $source
        );
        $this->assertStringContainsString('#34119', $source);
        $this->assertFileExists(
            dirname(__DIR__, 2).'/lib/JIT/Call/ReflectionClassGetReflectionConstants.php'
        );
        $this->assertFileExists(
            dirname(__DIR__, 2).'/lib/JIT/Builtin/ReflectionClassGetReflectionConstantsRuntime.php'
        );
    }

    public function testAotGetReflectionConstantsMatchesZend(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_34119_reflection_get_reflection_constants_aot.php';
        $bin = sys_get_temp_dir().'/phpc_34119_grc_'.getmypid().'.bin';
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

    public function testZendGetReflectionConstantsBaseline(): void
    {
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_34119_reflection_get_reflection_constants_aot.php';
        $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg($src).' 2>&1';
        exec($cmd, $out, $rc);
        $joined = trim(implode("\n", $out));
        $this->assertSame(0, $rc, $joined);
        $this->assertSame(self::EXPECT, $joined);
    }

    public function testVmGetReflectionConstantsMatchesZend(): void
    {
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_34119_reflection_get_reflection_constants_aot.php';
        $cmd = escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/vm.php').' '
            .escapeshellarg($src).' 2>&1';
        exec($cmd, $out, $rc);
        $joined = trim(implode("\n", $out));
        $this->assertSame(0, $rc, $joined);
        $this->assertSame(self::EXPECT, $joined);
    }
}
