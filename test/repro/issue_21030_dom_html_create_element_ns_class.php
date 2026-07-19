<?php
declare(strict_types=1);

/**
 * #21030 — Dom\HTMLDocument createElement/createElementNS class + HTML ns
 * (php-src ext/dom/php_dom.c dom_get_element_ce / document.c createElement).
 */
if (!class_exists(Dom\HTMLDocument::class)) {
    fwrite(STDERR, "skip: Dom\\HTMLDocument requires PHP_COMPILER_PROFILE=8.4\n");
    exit(0);
}

$htmlNs = 'http://www.w3.org/1999/xhtml';
$doc = Dom\HTMLDocument::createFromString('<!doctype html><html><body></body></html>');

$div = $doc->createElement('DIV');
echo 'createElement_class=', get_class($div), "\n";
echo 'createElement_name=', $div->nodeName, "\n";
echo 'createElement_ns=', var_export($div->namespaceURI, true), "\n";
echo 'createElement_html=', ($div instanceof Dom\HTMLElement) ? 'yes' : 'no', "\n";

$html = $doc->createElementNS($htmlNs, 'span');
echo 'htmlns_class=', get_class($html), "\n";
echo 'htmlns_html=', ($html instanceof Dom\HTMLElement) ? 'yes' : 'no', "\n";

$svg = $doc->createElementNS('http://www.w3.org/2000/svg', 'svg');
echo 'svg_class=', get_class($svg), "\n";
echo 'svg_html=', ($svg instanceof Dom\HTMLElement) ? 'yes' : 'no', "\n";
echo 'svg_element=', ($svg instanceof Dom\Element) ? 'yes' : 'no', "\n";

$custom = $doc->createElementNS('urn:x', 'x:y');
echo 'custom_class=', get_class($custom), "\n";
echo 'custom_html=', ($custom instanceof Dom\HTMLElement) ? 'yes' : 'no', "\n";

$nullNs = $doc->createElementNS(null, 'orphan');
echo 'nullns_class=', get_class($nullNs), "\n";
echo 'nullns_html=', ($nullNs instanceof Dom\HTMLElement) ? 'yes' : 'no', "\n";

$xd = Dom\XMLDocument::createFromString('<root/>');
$xe = $xd->createElementNS($htmlNs, 'div');
echo 'xml_htmlns_class=', get_class($xe), "\n";
echo 'xml_htmlns_html=', ($xe instanceof Dom\HTMLElement) ? 'yes' : 'no', "\n";

echo "ok\n";
