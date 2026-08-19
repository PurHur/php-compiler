<?php
declare(strict_types=1);

/**
 * AOT DOMNode::getLineNo() must not abort as object::getlineno().
 * php-src ext/dom/node.c PHP_METHOD(DOMNode, getLineNo) → xmlGetLineNo.
 */
$doc = new DOMDocument();
$doc->loadXML('<root id="x"><child/></root>');
echo $doc->documentElement->getLineNo(), '|', $doc->documentElement->firstChild->getLineNo(), "\n";

$lead = new DOMDocument();
$lead->loadXML("\n<root/>");
echo $lead->documentElement->getLineNo(), "\n";

$multi = new DOMDocument();
$multi->loadXML("<root>\n<child/>\n</root>");
echo $multi->documentElement->getLineNo(), "\n";
