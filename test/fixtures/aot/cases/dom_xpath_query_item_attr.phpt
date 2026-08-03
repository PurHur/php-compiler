--TEST--
AOT: DOMXPath::query() item(1) getAttribute (#27275)
--FILE--
<?php
declare(strict_types=1);
$dom = new DOMDocument();
$dom->loadXML('<r><a id="1"/><a id="2"/></r>');
$xp = new DOMXPath($dom);
$n = $xp->query('//a');
echo $n->length, "\n";
echo $n->item(1)->getAttribute('id'), "\n";
--EXPECT--
2
2
