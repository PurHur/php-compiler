--TEST--
AOT Dom\HTMLDocument/XMLDocument::createElement living classes (#28958)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
$html = Dom\HTMLDocument::createFromString('<!DOCTYPE html><html><body></body></html>');
$span = $html->createElement('span');
echo get_class($span), ' ', $span->nodeName, "\n";
$xml = Dom\XMLDocument::createFromString('<r/>');
$el = $xml->createElement('span');
echo get_class($el), ' ', $el->nodeName, "\n";
--EXPECT--
Dom\HTMLElement SPAN
Dom\Element span
