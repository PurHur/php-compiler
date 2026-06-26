<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\ModuleRegistry;
use PHPCompiler\ext\standard\VmReflection;
use PHPUnit\Framework\TestCase;

/**
 * dom extension module registration (issue #6140).
 *
 * @group dom_module
 */
final class DomModuleTest extends TestCase
{
    public function test_dom_module_registers_implementation(): void
    {
        $runtime = new Runtime();
        $ctx = $runtime->vmContext;

        self::assertTrue(VmReflection::classExists($ctx, 'DOMImplementation'));
        self::assertTrue(VmReflection::classExists($ctx, 'DOMDocument'));
        self::assertTrue(VmReflection::classExists($ctx, 'DOMDocumentType'));
        self::assertTrue(VmReflection::classExists($ctx, 'DOMElement'));
        self::assertTrue(ModuleRegistry::extensionLoaded('dom'));

        $code = <<<'PHP'
<?php
echo (int) class_exists('DOMImplementation', false);
PHP;
        $block = $runtime->parseAndCompile($code, 'dom_module.php');
        ob_start();
        $runtime->run($block);
        self::assertSame('1', ob_get_clean());
    }

    public function test_runtime_shrink_has_no_dom_c_runtime(): void
    {
        $linker = (string) file_get_contents(__DIR__.'/../../lib/AOT/Linker.php');
        self::assertStringNotContainsString('phpc_dom', $linker);
        self::assertStringNotContainsString('dom_', $linker);
    }
}
