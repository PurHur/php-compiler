<?php
/**
 * #24775 — DOMDocument::xinclude() must apply libxml xml:base fixup.
 */
libxml_use_internal_errors(true);
$dir = sys_get_temp_dir() . '/dom_xi_base_' . getmypid();
if (!is_dir($dir)) {
    mkdir($dir);
}
file_put_contents($dir . '/inc.xml', '<inc>Y</inc>');
$abs = $dir . '/inc.xml';

// Cross-directory absolute href → relative xml:base containing '/'.
$doc = new DOMDocument();
$doc->loadXML(
    '<?xml version="1.0"?><root xmlns:xi="http://www.w3.org/2001/XInclude">'
    . '<xi:include href="' . $abs . '"/></root>'
);
$n = $doc->xinclude();
$el = $doc->documentElement->firstChild;
echo 'n=', var_export($n, true), "\n";
echo 'tag=', $el->nodeName, "\n";
$base = $el->getAttributeNS('http://www.w3.org/XML/1998/namespace', 'base');
echo 'has_slash=', (str_contains($base, '/') ? '1' : '0'), "\n";
echo 'ends=', (str_ends_with($base, 'inc.xml') ? '1' : '0'), "\n";
echo 'baseURI_ends=', (str_ends_with((string) $el->baseURI, 'inc.xml') ? '1' : '0'), "\n";

// Same-directory include via load(file) → omit xml:base (no slash in relative).
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
echo 'same_dir_base=', var_export($base2, true), "\n";

@unlink($dir . '/inc.xml');
@unlink($dir . '/outer.xml');
@rmdir($dir);
