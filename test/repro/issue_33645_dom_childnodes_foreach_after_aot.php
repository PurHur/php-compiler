<?php
/**
 * #33645 — AOT foreach over childNodes must see siblings added by after/before/append/prepend.
 * php-src: ext/dom/nodelist.c InternalIterator; ext/dom/php_dom.c ChildNode::after.
 */
$doc = new DOMDocument();
$doc->loadXML('<r><a/></r>');
$doc->documentElement->firstChild->after($doc->createElement('b'));
$names = [];
foreach ($doc->documentElement->childNodes as $n) {
    if ($n->nodeType === XML_ELEMENT_NODE) {
        $names[] = $n->nodeName;
    }
}
echo implode(',', $names), "\n";
