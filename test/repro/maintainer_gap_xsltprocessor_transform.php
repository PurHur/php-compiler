<?php

declare(strict_types=1);

$xml = '<?xml version="1.0"?><doc><title>Hi</title></doc>';
$xsl = '<?xml version="1.0"?>
<xsl:stylesheet version="1.0" xmlns:xsl="http://www.w3.org/1999/XSL/Transform">
  <xsl:template match="/">
    <html><body><h1><xsl:value-of select="doc/title"/></h1></body></html>
  </xsl:template>
</xsl:stylesheet>';

if (!class_exists('XSLTProcessor', false)) {
    fwrite(STDERR, "skip: XSLTProcessor class missing\n");
    exit(77);
}

$proc = new XSLTProcessor();
$dom = new DOMDocument();
$dom->loadXML($xml);
$xslDom = new DOMDocument();
$xslDom->loadXML($xsl);
$proc->importStylesheet($xslDom);
$result = $proc->transformToXML($dom);
if (!is_string($result) || !str_contains($result, '<h1>Hi</h1>')) {
    fwrite(STDERR, 'fail: transformToXML missing expected h1 output: '.var_export($result, true)."\n");
    exit(1);
}

$docResult = $proc->transformToDoc($dom);
if (!($docResult instanceof DOMDocument)) {
    fwrite(STDERR, "fail: transformToDoc did not return DOMDocument\n");
    exit(1);
}
$h1 = $docResult->getElementsByTagName('h1');
if (1 !== $h1->length || 'Hi' !== $h1->item(0)->textContent) {
    fwrite(STDERR, "fail: transformToDoc h1 text mismatch\n");
    exit(1);
}

echo "xsltprocessor_transform_ok\n";
