<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\libxml\VmLibxml;
use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** libxml entity loader API in ext/libxml PHP — no runtime/*.c (#6379, #14953). */
final class VmLibxmlEntityLoaderRuntimeShrinkTest extends TestCase
{
    protected function setUp(): void
    {
        VmLibxml::resetEntityLoaderStateForTest();
    }

    public function testLibxmlEntityLoaderFunctionsRegistered(): void
    {
        $runtime = new Runtime();
        $ctx = $runtime->vmContext;
        foreach ([
            'libxml_disable_entity_loader',
            'libxml_set_external_entity_loader',
            'libxml_get_external_entity_loader',
        ] as $fn) {
            self::assertTrue(VmReflection::functionExists($ctx, $fn), $fn);
        }
    }

    public function testDisableEntityLoaderTogglesAndReturnsPrevious(): void
    {
        VmLibxml::resetEntityLoaderStateForTest();
        self::assertFalse(VmLibxml::disableEntityLoader());
        self::assertTrue(VmLibxml::entityLoaderDisabled());
        self::assertTrue(VmLibxml::disableEntityLoader(true));
        self::assertTrue(VmLibxml::entityLoaderDisabled());
        self::assertTrue(VmLibxml::disableEntityLoader(false));
        self::assertFalse(VmLibxml::entityLoaderDisabled());
    }

    public function testExternalEntityLoaderRoundTripVm(): void
    {
        VmLibxml::resetEntityLoaderStateForTest();
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
echo (int) (null === libxml_get_external_entity_loader()), "\n";
$ok = libxml_set_external_entity_loader('strlen');
echo (int) $ok, "\n";
$loader = libxml_get_external_entity_loader();
echo is_callable($loader) ? "callable\n" : "not\n";
$ok2 = libxml_set_external_entity_loader(null);
echo (int) $ok2, "\n";
echo (int) (null === libxml_get_external_entity_loader()), "\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'libxml_entity_loader.php');
        ob_start();
        $runtime->run($block);
        self::assertSame("1\n1\ncallable\n1\n1\n", ob_get_clean());
    }

    public function testReproScriptPrintsOk(): void
    {
        VmLibxml::resetEntityLoaderStateForTest();
        $runtime = new Runtime();
        $path = __DIR__.'/../../test/repro/libxml_entity_loader_missing.php';
        $block = $runtime->parseAndCompileFile($path);
        ob_start();
        $runtime->run($block);
        $out = ob_get_clean();
        self::assertStringContainsString("libxml_disable_entity_loader: yes\n", $out);
        self::assertStringContainsString("libxml_set_external_entity_loader: yes\n", $out);
        self::assertStringContainsString("libxml_get_external_entity_loader: yes\n", $out);
        self::assertStringEndsWith("ok\n", $out);
    }

    public function testNoRuntimeCGrowthForLibxmlEntityLoader(): void
    {
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/AOT/runtime/libxml.c');
        $this->assertFileDoesNotExist(__DIR__.'/../../runtime/libxml.c');
    }
}
