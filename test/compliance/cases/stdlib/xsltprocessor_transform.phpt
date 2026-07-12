--TEST--
stdlib XSLTProcessor::transformToXML() basic HTML output (#3665, ext/xsl/xsltprocessor.c)
--SKIPIF--
<?php
if (!extension_loaded('xsl') || !extension_loaded('dom')) {
    echo 'skip';
}
?>
--FILE--
<?php
$xml = '<?xml version="1.0"?><doc><title>Hi</title></doc>';
$xsl = '<?xml version="1.0"?>
<xsl:stylesheet version="1.0" xmlns:xsl="http://www.w3.org/1999/XSL/Transform">
  <xsl:template match="/">
    <html><body><h1><xsl:value-of select="doc/title"/></h1></body></html>
  </xsl:template>
</xsl:stylesheet>';
echo (int) class_exists('XSLTProcessor', false), "\n";
$proc = new XSLTProcessor();
$dom = new DOMDocument();
$dom->loadXML($xml);
$xslDom = new DOMDocument();
$xslDom->loadXML($xsl);
$proc->importStylesheet($xslDom);
$result = $proc->transformToXML($dom);
echo (int) is_string($result), "\n";
echo (int) str_contains($result, '<h1>Hi</h1>'), "\n";
$docResult = $proc->transformToDoc($dom);
echo (int) ($docResult instanceof DOMDocument), "\n";
echo $docResult->getElementsByTagName('h1')->item(0)->textContent, "\n";
?>
--EXPECT--
1
1
1
1
Hi
