<?php
/**
 * Repro #22632 — XSLTProcessor::registerPHPFunctions() + php:function() must call VM userland.
 */
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
