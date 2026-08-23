<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * AOT: ReflectionExtension construct must set readable Zend public $name (#34008).
 *
 * @see php-src ext/reflection/php_reflection.c reflection_extension_object
 * @see \PHPCompiler\VM\ReflectionSupport::PROP_EXTENSION_NAME
 *
 * @group llvm
 * @group aot
 */
final class Issue34008ReflectionExtensionNameAotTest extends TestCase
{
    public function testPropExtensionNameIsZendPublicName(): void
    {
        $this->assertSame(
            'name',
            \PHPCompiler\VM\ReflectionSupport::PROP_EXTENSION_NAME
        );
    }

    public function testObjectLayoutUsesNameAndHasConstructor(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2).'/lib/JIT/Builtin/Type/Object_.php'
        );
        $this->assertMatchesRegularExpression(
            "/'reflectionextension' === \\\$lcname.*?PROP_EXTENSION_NAME.*?TYPE_VALUE.*?markHasConstructor/s",
            $source
        );
        $this->assertStringContainsString('#34008', $source);
    }

    public function testVmNameAndGetName(): void
    {
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_34008_reflection_extension_name_aot.php';
        $cmd = escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/vm.php').' '
            .escapeshellarg($src).' 2>&1';
        exec($cmd, $out, $rc);
        $joined = implode("\n", $out);
        $this->assertSame(0, $rc, $joined);
        $this->assertSame("standard\nstandard", trim($joined));
    }

    public function testAotNameAndGetName(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_34008_reflection_extension_name_aot.php';
        $bin = sys_get_temp_dir().'/phpc_34008_re_'.getmypid().'.bin';
        $compile = 'env PHP_COMPILER_HELPER_RUNTIME_O=0 '
            .escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        try {
            $runOut = [];
            exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
            $joined = implode("\n", $runOut);
            $this->assertSame(0, $runRc, $joined);
            $this->assertSame("standard\nstandard", trim($joined));
        } finally {
            @unlink($bin);
        }
    }
}
