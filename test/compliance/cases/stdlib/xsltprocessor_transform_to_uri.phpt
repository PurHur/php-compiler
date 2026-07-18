--TEST--
stdlib XSLTProcessor::transformToUri() writes result URI (#20391, ext/xsl/xsltprocessor.c)
--SKIPIF--
<?php
if (!extension_loaded('xsl') || !extension_loaded('dom')) {
    echo 'skip';
}
?>
--FILE--
<?php
$xml = new DOMDocument();
$xml->loadXML('<root><a>1</a></root>');
$xsl = new DOMDocument();
$xsl->loadXML('<?xml version="1.0"?><xsl:stylesheet version="1.0" xmlns:xsl="http://www.w3.org/1999/XSL/Transform"><xsl:template match="/"><out><xsl:value-of select="root/a"/></out></xsl:template></xsl:stylesheet>');
$p = new XSLTProcessor();
$p->importStylesheet($xsl);
echo (int) method_exists($p, 'transformToUri'), "\n";
// Keep the tempnam inode — VM unlink tombstones the path and hides a later host write.
$uri = tempnam(sys_get_temp_dir(), 'xo');
$n = $p->transformToUri($xml, $uri);
$body = (string) file_get_contents($uri);
echo (int) is_int($n), "\n";
echo (int) ($n === strlen($body)), "\n";
echo (int) str_contains($body, '<out>1</out>'), "\n";
@unlink($uri);
?>
--EXPECT--
1
1
1
1
