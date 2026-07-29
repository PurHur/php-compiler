--TEST--
DOMDocument::xinclude() libxml xml:base fixup (#24775, ext/dom/document.c)
--FILE--
<?php
declare(strict_types=1);
libxml_use_internal_errors(true);
$dir = sys_get_temp_dir() . '/dom_xinclude_base_' . getmypid();
if (!is_dir($dir)) {
    mkdir($dir);
}
file_put_contents($dir . '/inc.xml', '<inc>Y</inc>');
$abs = $dir . '/inc.xml';

$doc = new DOMDocument();
$doc->loadXML(
    '<?xml version="1.0"?><root xmlns:xi="http://www.w3.org/2001/XInclude">'
    . '<xi:include href="' . $abs . '"/></root>'
);
$n = $doc->xinclude();
$el = $doc->documentElement->firstChild;
$base = $el->getAttributeNS('http://www.w3.org/XML/1998/namespace', 'base');
echo 'cross_n=', var_export($n, true), "\n";
echo 'cross_slash=', (str_contains($base, '/') ? '1' : '0'), "\n";
echo 'cross_ends=', (str_ends_with($base, 'inc.xml') ? '1' : '0'), "\n";
echo 'cross_baseURI=', (str_ends_with((string) $el->baseURI, 'inc.xml') ? '1' : '0'), "\n";

file_put_contents(
    $dir . '/outer.xml',
    '<?xml version="1.0"?><root xmlns:xi="http://www.w3.org/2001/XInclude">'
    . '<xi:include href="inc.xml"/></root>'
);
$doc2 = new DOMDocument();
$doc2->load($dir . '/outer.xml');
$doc2->xinclude();
$el2 = $doc2->documentElement->firstChild;
$base2 = $el2->getAttributeNS('http://www.w3.org/XML/1998/namespace', 'base');
echo 'same_base=', var_export($base2, true), "\n";

@unlink($dir . '/inc.xml');
@unlink($dir . '/outer.xml');
@rmdir($dir);
--EXPECT--
cross_n=1
cross_slash=1
cross_ends=1
cross_baseURI=1
same_base=''
