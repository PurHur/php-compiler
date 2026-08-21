<?php
/**
 * #33659 — AOT getElementsByTagName must see elements added by appendChild/after.
 * php-src: ext/dom/nodelist.c live list; ext/dom/php_dom.c dom_get_elements_by_tag_name.
 */
$doc = new DOMDocument();
$doc->loadXML('<r><a/></r>');
$doc->documentElement->appendChild($doc->createElement('b'));
$list = $doc->getElementsByTagName('*');
echo 'len=', $list->length, "\n";
$names = [];
foreach ($list as $n) {
    if ($n->nodeType === XML_ELEMENT_NODE) {
        $names[] = $n->nodeName;
    }
}
echo implode(',', $names), "\n";
