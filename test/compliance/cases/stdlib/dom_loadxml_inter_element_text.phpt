--TEST--
Stdlib: DOMDocument::loadXML() preserves mixed-content text child nodes (#15501, ext/dom/php_dom.c)
--FILE--
<?php
$doc = new DOMDocument();
$doc->loadXML('<r>before<e/>after</r>');
$parts = [];
foreach ($doc->documentElement->childNodes as $n) {
    $parts[] = ($n->nodeType === XML_TEXT_NODE ? 't:' : 'e:').$n->nodeName;
}
echo implode(',', $parts), "\n";
echo $doc->saveXML($doc->documentElement), "\n";
?>
--EXPECT--
t:#text,e:e,t:#text
<r>before<e/>after</r>
