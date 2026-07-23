<?php
$xml = new DOMDocument();
$xml->loadXML("<root><a>1</a></root>");
$xsl = new DOMDocument();
$xsl->loadXML('<?xml version="1.0"?><xsl:stylesheet version="1.0" xmlns:xsl="http://www.w3.org/1999/XSL/Transform"><xsl:template match="/"><out><xsl:value-of select="root/a"/></out></xsl:template></xsl:stylesheet>');
$p = new XSLTProcessor();
var_export($p->importStylesheet($xsl));
echo PHP_EOL;
$bad = new DOMDocument();
$bad->loadXML("<not-xsl/>");
var_export(@(new XSLTProcessor())->importStylesheet($bad));
echo PHP_EOL;
