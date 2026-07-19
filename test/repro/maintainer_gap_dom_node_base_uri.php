<?php
/**
 * Dom\Node::$baseURI falls back to document URI about:blank (#21056).
 * php-src: ext/dom/node.c dom_node_base_uri_read
 */
$html = '<html><body><div id="x">hi</div></body></html>';
$doc = Dom\HTMLDocument::createFromString($html, LIBXML_NOERROR);
$el = $doc->getElementById('x');
echo 'documentURI=', var_export($doc->documentURI, true), "\n";
echo 'el_baseURI=', var_export($el->baseURI, true), "\n";
echo 'child_baseURI=', var_export($el->firstChild->baseURI, true), "\n";
echo 'isset_baseURI=', var_export(isset($el->baseURI), true), "\n";
