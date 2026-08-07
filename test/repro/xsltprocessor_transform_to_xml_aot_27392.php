<?php

/**
 * AOT XSLTProcessor::transformToXML must match Zend/VM string (not NULL) (#27392).
 */
$xml = new DOMDocument();
$xml->loadXML('<a><b>1</b></a>');
$xsl = new DOMDocument();
$xsl->loadXML('<xsl:stylesheet version="1.0" xmlns:xsl="http://www.w3.org/1999/XSL/Transform"><xsl:template match="/"><out><xsl:value-of select="a/b"/></out></xsl:template></xsl:stylesheet>');
$p = new XSLTProcessor();
$p->importStylesheet($xsl);
var_dump($p->transformToXML($xml));
