<?php
/**
 * #33679 — AOT getElementsByTagName length after insertBefore (no pending+XML double-count).
 * php-src: ext/dom/nodelist.c; ext/dom/node.c dom_node_insert_before.
 */
$doc = new DOMDocument();
$doc->loadXML('<r><a/><c/></r>');
$r = $doc->documentElement;
$r->insertBefore($doc->createElement('b'), $r->lastChild);
$list = $doc->getElementsByTagName('*');
echo 'len=', $list->length, "\n";
$names = [];
foreach ($list as $n) {
    if ($n->nodeType === XML_ELEMENT_NODE) {
        $names[] = $n->nodeName;
    }
}
echo implode(',', $names), "\n";
