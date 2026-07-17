--TEST--
stdlib XSLTProcessor::setParameter/getParameter/registerPHPFunctions (#19872, ext/xsl/xsltprocessor.c)
--SKIPIF--
<?php
if (!extension_loaded('xsl') || !extension_loaded('dom')) {
    echo 'skip';
}
?>
--FILE--
<?php
$xml = new DOMDocument();
$xml->loadXML('<r/>');
$xsl = new DOMDocument();
$xsl->loadXML('<xsl:stylesheet version="1.0" xmlns:xsl="http://www.w3.org/1999/XSL/Transform"><xsl:param name="p">def</xsl:param><xsl:template match="/"><out><xsl:value-of select="$p"/></out></xsl:template></xsl:stylesheet>');
$p = new XSLTProcessor();
$p->importStylesheet($xsl);
echo 'has_set=', method_exists($p, 'setParameter') ? '1' : '0', "\n";
echo 'has_get=', method_exists($p, 'getParameter') ? '1' : '0', "\n";
echo 'has_reg=', method_exists($p, 'registerPHPFunctions') ? '1' : '0', "\n";
$p->setParameter('', 'p', 'hi');
echo trim($p->transformToXML($xml)), "\n";
echo $p->getParameter('', 'p'), "\n";
echo (int) $p->removeParameter('', 'p'), "\n";
?>
--EXPECT--
has_set=1
has_get=1
has_reg=1
<?xml version="1.0"?>
<out>hi</out>
hi
1
