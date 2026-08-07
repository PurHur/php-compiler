<?php
/**
 * #28555 — DOM live collection length before nullsafe method in same array literal.
 * Zend keeps length as int; VM/JIT were storing null (temp/slot reuse with ?->).
 */
$doc = new DOMDocument();
$doc->loadXML('<r x="1" y="2"><a/></r>');
$attrs = $doc->documentElement->attributes;
$list = $doc->getElementsByTagName('a');
$cn = $doc->documentElement->childNodes;

$a1 = [$attrs->length, $attrs->getNamedItem('x')?->nodeValue];
echo 'attrs=' . json_encode($a1) . "\n";

$a2 = [$list->length, $list->item(0)?->nodeName];
echo 'list=' . json_encode($a2) . "\n";

$a3 = [$cn->length, $cn->item(0)?->nodeName];
echo 'childNodes=' . json_encode($a3) . "\n";
