--TEST--
dom live DOMNodeList foreach advances for getElementsByTagName* (#19416)
--FILE--
<?php
$doc = new DOMDocument();
$doc->loadXML('<r xmlns:n="urn:n"><a/><b/><c/><n:x/></r>');

$list = $doc->getElementsByTagName('*');
echo 'doc_star_len=', $list->length, "\n";
foreach ($list as $k => $node) {
    echo "doc_star k=$k name=", $node->nodeName, "\n";
}

$ns = $doc->getElementsByTagNameNS('urn:n', '*');
echo 'ns_len=', $ns->length, "\n";
foreach ($ns as $k => $node) {
    echo "ns k=$k name=", $node->nodeName, "\n";
}

$el = $doc->documentElement->getElementsByTagName('b');
echo 'el_b_len=', $el->length, "\n";
foreach ($el as $k => $node) {
    echo "el_b k=$k name=", $node->nodeName, "\n";
}

echo "done\n";
--EXPECT--
doc_star_len=5
doc_star k=0 name=r
doc_star k=1 name=a
doc_star k=2 name=b
doc_star k=3 name=c
doc_star k=4 name=n:x
ns_len=1
ns k=0 name=n:x
el_b_len=1
el_b k=0 name=b
done
