<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\ModuleRegistry;
use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\ext\xsl\XsltHostBridge;
use PHPUnit\Framework\TestCase;

/**
 * xsl extension module registration (issue #3665).
 *
 * @group xsl_module
 */
final class XslModuleTest extends TestCase
{
    public function test_xsl_module_registers_xsltprocessor_when_host_ext_available(): void
    {
        if (!XsltHostBridge::available()) {
            self::markTestSkipped('host ext/xsl not loaded');
        }

        $runtime = new Runtime();
        $ctx = $runtime->vmContext;

        self::assertTrue(ModuleRegistry::extensionLoaded('xsl'));
        self::assertTrue(VmReflection::classExists($ctx, 'XSLTProcessor'));

        $code = <<<'PHP'
<?php
$xml = '<?xml version="1.0"?><doc><title>Hi</title></doc>';
$xsl = '<?xml version="1.0"?>
<xsl:stylesheet version="1.0" xmlns:xsl="http://www.w3.org/1999/XSL/Transform">
  <xsl:template match="/">
    <html><body><h1><xsl:value-of select="doc/title"/></h1></body></html>
  </xsl:template>
</xsl:stylesheet>';
$proc = new XSLTProcessor();
$dom = new DOMDocument();
$dom->loadXML($xml);
$xslDom = new DOMDocument();
$xslDom->loadXML($xsl);
$imported = $proc->importStylesheet($xslDom);
echo (int) (true === $imported), "\n";
$result = $proc->transformToXML($dom);
echo (int) str_contains($result, '<h1>Hi</h1>'), "\n";
$docResult = $proc->transformToDoc($dom);
echo $docResult->getElementsByTagName('h1')->item(0)->textContent, "\n";
$uri = tempnam(sys_get_temp_dir(), 'xslt_uri_');
$n = $proc->transformToUri($dom, $uri);
echo (int) is_int($n), "\n";
echo (int) str_contains((string) file_get_contents($uri), '<h1>Hi</h1>'), "\n";
@unlink($uri);
PHP;
        $block = $runtime->parseAndCompile($code, 'xsl_module.php');
        ob_start();
        $runtime->run($block);
        self::assertSame("1\n1\nHi\n1\n1\n", ob_get_clean());
    }
}
