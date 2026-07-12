<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** DOM ↔ SimpleXML bridge wiring (#6057). */
final class DomSimpleXmlBridgeTest extends TestCase
{
    public function testDomModuleRegistersImportSimplexml(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/dom/Module.php');
        $this->assertStringContainsString('dom_import_simplexml', $source);
    }

    public function testSimpleXmlModuleRegistersImportDom(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/simplexml/Module.php');
        $this->assertStringContainsString('simplexml_import_dom', $source);
    }

    public function testBridgeUsesPhpInPhpConversion(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/dom/VmDomSimpleXmlBridge.php');
        $this->assertStringContainsString('SimpleXmlNodeState', $source);
        $this->assertStringContainsString('DomRegistry', $source);
    }
}
