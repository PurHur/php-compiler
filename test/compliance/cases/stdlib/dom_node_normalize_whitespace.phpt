--TEST--
stdlib DOMNode::normalize() — whitespace-only inter-element text preserved (#17516, ext/dom/node.c)
--FILE--
<?php
$dom = new DOMDocument();
$dom->loadXML('<root>  <a/>  <b/>  </root>');
$dom->documentElement->normalize();
echo $dom->saveXML($dom->documentElement), "\n";
?>
--EXPECT--
<root>  <a/>  <b/>  </root>
