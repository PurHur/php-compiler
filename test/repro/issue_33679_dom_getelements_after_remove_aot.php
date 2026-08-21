<?php
/**
 * #33679 — AOT getElementsByTagName length after appendChild + removeChild.
 * php-src: ext/dom/nodelist.c live list; ext/dom/node.c dom_node_remove_child.
 */
$doc = new DOMDocument();
$doc->loadXML('<r><a/></r>');
$b = $doc->createElement('b');
$doc->documentElement->appendChild($b);
$doc->documentElement->removeChild($b);
$list = $doc->getElementsByTagName('*');
echo 'len=', $list->length, "\n";
$names = [];
foreach ($list as $n) {
    if ($n->nodeType === XML_ELEMENT_NODE) {
        $names[] = $n->nodeName;
    }
}
echo implode(',', $names), "\n";
