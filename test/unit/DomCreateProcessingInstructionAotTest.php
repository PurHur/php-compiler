<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * AOT createProcessingInstruction uses a JIT Call proxy (#32331, peer createComment #32315).
 */
final class DomCreateProcessingInstructionAotTest extends TestCase
{
    public function testJitProxyAndSaveXmlDumpPiKind(): void
    {
        $jit = (string) file_get_contents(__DIR__.'/../../lib/JIT/DomInstanceMethodJit.php');
        $this->assertStringContainsString("'domdocument::createprocessinginstruction'", $jit);
        $this->assertStringContainsString('DomDocumentCreateProcessingInstruction', $jit);
        $this->assertStringContainsString('#32331', $jit);

        $helper = (string) file_get_contents(__DIR__.'/../../ext/dom/JitDomCreateProcessingInstruction.php');
        $this->assertStringContainsString('#32331', $helper);
        $this->assertStringContainsString("TAG_KIND = '#pi'", $helper);
        $this->assertStringContainsString('xmlNewDocPI', $helper);

        $save = (string) file_get_contents(__DIR__.'/../../ext/dom/JitDomSaveXMLUserScript.php');
        $this->assertStringContainsString('JitDomCreateProcessingInstruction::TAG_KIND', $save);
        $this->assertStringContainsString('dom_savexml_pi', $save);
        $this->assertStringContainsString('#32331', $save);
    }
}
