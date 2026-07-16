--TEST--
stdlib DOMNode::cloneNode(true) preserves text/CDATA (#19359, ext/dom/node.c)
--FILE--
<?php
$dom = new DOMDocument();
$dom->loadXML('<root><a><b>t</b></a></root>');
$c = $dom->getElementsByTagName('a')->item(0)->cloneNode(true);
echo 'clone=', $c->textContent, "\n";
echo 'xml=', $dom->saveXML($c), "\n";

$dom2 = new DOMDocument();
$dom2->loadXML('<root><![CDATA[x<y>]]></root>');
$cd = $dom2->documentElement->firstChild;
echo get_class($cd), "\n";
echo $cd->cloneNode(true)->data, "\n";

$dom3 = new DOMDocument();
$dom3->loadXML('<root><!--c--><a>x</a></root>');
$rootClone = $dom3->documentElement->cloneNode(true);
echo 'mixed=', $dom3->saveXML($rootClone), "\n";
--EXPECT--
clone=t
xml=<a><b>t</b></a>
DOMCdataSection
x<y>
mixed=<root><!--c--><a>x</a></root>
