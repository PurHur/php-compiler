--TEST--
stdlib XSLTProcessor::registerPHPFunctions() php:function() — VM userland handlers (#22632, ext/xsl/xsltprocessor.c)
--SKIPIF--
<?php
if (!extension_loaded('xsl') || !extension_loaded('dom')) {
    echo 'skip';
}
?>
--FILE--
<?php
function xsl_uc($s) {
    return strtoupper((string) $s);
}
$xml = new DOMDocument();
$xml->loadXML('<r><a>hi</a></r>');
$xsl = new DOMDocument();
$xsl->loadXML(
    '<xsl:stylesheet version="1.0"'
    .' xmlns:xsl="http://www.w3.org/1999/XSL/Transform"'
    .' xmlns:php="http://php.net/xsl">'
    .'<xsl:template match="/"><out>'
    .'<xsl:value-of select="php:function(\'xsl_uc\', string(//a))"/>'
    .'</out></xsl:template></xsl:stylesheet>'
);
$p = new XSLTProcessor();
$p->importStylesheet($xsl);
$p->registerPHPFunctions();
echo trim($p->transformToXML($xml)), "\n";

$p2 = new XSLTProcessor();
$p2->importStylesheet($xsl);
$p2->registerPHPFunctions(['xsl_uc']);
echo trim($p2->transformToXML($xml)), "\n";
?>
--EXPECT--
<?xml version="1.0"?>
<out xmlns:php="http://php.net/xsl">HI</out>
<?xml version="1.0"?>
<out xmlns:php="http://php.net/xsl">HI</out>
