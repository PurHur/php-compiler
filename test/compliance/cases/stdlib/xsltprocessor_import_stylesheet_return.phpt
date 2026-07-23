--TEST--
stdlib XSLTProcessor::importStylesheet() returns bool (#22367, ext/xsl/xsltprocessor.c)
--SKIPIF--
<?php
if (!extension_loaded('xsl') || !extension_loaded('dom') || !class_exists('XSLTProcessor', false)) {
    echo 'skip';
}
?>
--FILE--
<?php
$xsl = new DOMDocument();
$xsl->loadXML('<?xml version="1.0"?><xsl:stylesheet version="1.0" xmlns:xsl="http://www.w3.org/1999/XSL/Transform"><xsl:template match="/"><out/></xsl:template></xsl:stylesheet>');
$p = new XSLTProcessor();
$ok = $p->importStylesheet($xsl);
echo 'ok=', var_export($ok, true), "\n";
echo 'ok_type=', gettype($ok), "\n";

$bad = new DOMDocument();
$bad->loadXML('<not-xsl/>');
$fail = @(new XSLTProcessor())->importStylesheet($bad);
echo 'fail=', var_export($fail, true), "\n";
echo 'fail_type=', gettype($fail), "\n";
?>
--EXPECT--
ok=true
ok_type=boolean
fail=false
fail_type=boolean
