<?php
/**
 * Dom\Element::$nodeValue is always null; text lives in textContent (#21054).
 * php-src: ext/dom/node.c dom_node_node_value_read (modern Element)
 */
$html = '<html><body><div id="x">hi</div></body></html>';
$doc = Dom\HTMLDocument::createFromString($html, LIBXML_NOERROR);
$el = $doc->getElementById('x');
echo 'nodeValue=', var_export($el->nodeValue, true), "\n";
echo 'isset_nodeValue=', var_export(isset($el->nodeValue), true), "\n";
echo 'textContent=', var_export($el->textContent, true), "\n";
try {
    $el->nodeValue = 'nope';
    echo "wrote\n";
} catch (Error $e) {
    echo 'write=', $e->getMessage(), "\n";
}
// Legacy DOMElement still concatenates (#19455).
$legacy = new DOMDocument();
$legacy->loadXML('<r>ab</r>');
echo 'legacy_nodeValue=', var_export($legacy->documentElement->nodeValue, true), "\n";
