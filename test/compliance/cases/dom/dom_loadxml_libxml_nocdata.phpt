--TEST--
DOMDocument::loadXML LIBXML_NOCDATA merges CDATA into text (#22754, ext/dom/document.c)
--FILE--
<?php
$xml = '<r><![CDATA[hello]]></r>';
$dom = new DOMDocument();
$dom->loadXML($xml, LIBXML_NOCDATA);
$ch = $dom->documentElement->firstChild;
echo 'nocdata=', $ch->nodeName, ' value=', $ch->nodeValue, "\n";

$dom2 = new DOMDocument();
$dom2->loadXML($xml);
$ch2 = $dom2->documentElement->firstChild;
echo 'default=', $ch2->nodeName, "\n";

$dom3 = new DOMDocument();
$dom3->loadXML('<r>a<![CDATA[b]]>c</r>', LIBXML_NOCDATA);
echo 'coalesce=', $dom3->documentElement->firstChild->nodeValue, ' len=', $dom3->documentElement->childNodes->length, "\n";

$dom4 = new DOMDocument();
$dom4->loadXML('<r>a<!--c--><![CDATA[b]]>d</r>', LIBXML_NOCDATA);
echo 'sep_len=', $dom4->documentElement->childNodes->length, "\n";
for ($i = 0; $i < $dom4->documentElement->childNodes->length; $i++) {
    $n = $dom4->documentElement->childNodes->item($i);
    echo 'sep_', $i, '=', $n->nodeName, ':', $n->nodeValue, "\n";
}

$dom5 = new DOMDocument();
$dom5->loadXML('<r><![CDATA[]]></r>', LIBXML_NOCDATA);
$empty = $dom5->documentElement->firstChild;
echo 'empty=', $empty->nodeName, ' len=', strlen($empty->nodeValue), "\n";
--EXPECT--
nocdata=#text value=hello
default=#cdata-section
coalesce=abc len=1
sep_len=3
sep_0=#text:a
sep_1=#comment:c
sep_2=#text:bd
empty=#text len=0
