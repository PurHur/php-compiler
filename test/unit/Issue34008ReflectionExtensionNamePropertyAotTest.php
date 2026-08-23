<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * AOT/VM: ReflectionExtension::$name matches Zend public surface (#34008).
 *
 * @see php-src ext/reflection/php_reflection.c zim_ReflectionExtension___construct
 *
 * @group llvm
 * @group aot
 */
final class Issue34008ReflectionExtensionNamePropertyAotTest extends TestCase
{
    public function testPropExtensionNameIsZendPublicName(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2).'/lib/VM/ReflectionSupport.php'
        );
        $this->assertMatchesRegularExpression(
            "/PROP_EXTENSION_NAME\s*=\s*'name'/",
            $source
        );
        $this->assertStringNotContainsString(
            "PROP_EXTENSION_NAME = 'extension'",
            $source
        );
    }

    public function testAotNameAndGetName(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_34008_reflection_extension_name_property_aot.php';
        $bin = sys_get_temp_dir().'/phpc_34008_re_'.getmypid().'.bin';
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
                $this->assertNotSame(139, $runRc, 'run '.$i.': '.$joined);
                $this->assertSame(0, $runRc, 'run '.$i.': '.$joined);
                $this->assertSame('standard|standard', trim($joined));
            }
        } finally {
            @unlink($bin);
        }
    }

    public function testVmNamePropertyNoWarning(): void
    {
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_34008_reflection_extension_name_property_aot.php';
        $cmd = escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/vm.php').' '
            .escapeshellarg($src).' 2>&1';
        exec($cmd, $out, $rc);
        $joined = implode("\n", $out);
        $this->assertSame(0, $rc, $joined);
        $this->assertStringNotContainsString('Undefined property', $joined);
        $this->assertSame('standard|standard', trim($joined));
    }
}
