--TEST--
AOT: DOMXPath::evaluate() boolean/count scalars (#18526, #18392, #18844)
--FILE--
<?php
declare(strict_types=1);
$doc = new DOMDocument();
$doc->loadXML('<root><child id="1">a</child></root>');
$xpath = new DOMXPath($doc);
$bool = $xpath->evaluate('boolean(//child)');
echo (int) $bool, "\n";
$count = $xpath->evaluate('count(//child)');
echo (int) $count, "\n";
$empty = new DOMDocument();
$empty->loadXML('<root><child/></root>');
$emptyXpath = new DOMXPath($empty);
echo (int) $emptyXpath->evaluate('boolean(//child)'), "\n";
echo (int) $emptyXpath->evaluate('boolean(count(//child))'), "\n";
--EXPECT--
1
1
1
1
