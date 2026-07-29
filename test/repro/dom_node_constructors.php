<?php
// Issue #24631 — public constructors for orphaned DOM leaf nodes.
echo (new DOMComment('hi'))->data, "\n";
echo (new DOMText('hi'))->data, "\n";
echo (new DOMCdataSection('x'))->data, "\n";
echo (new DOMProcessingInstruction('t', 'd'))->target, "\n";
echo (new DOMEntityReference('amp'))->nodeName, "\n";
echo (new DOMAttr('id', '1'))->value, "\n";
$doc = new DOMDocument();
$doc->loadXML('<r/>');
$doc->documentElement->appendChild(new DOMComment('x'));
echo trim($doc->saveXML($doc->documentElement)), "\n";
