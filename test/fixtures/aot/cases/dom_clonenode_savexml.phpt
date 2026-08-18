--TEST--
AOT: cloneNode saveXML must not abort as object::clonenode (#32355, ext/dom/node.c)
--FILE--
<?php
declare(strict_types=1);
$doc = new DOMDocument();
$doc->loadXML('<root><child id="1"><inner/></child></root>');
$child = $doc->documentElement->firstChild;
$deep = $child->cloneNode(true);
$shallow = $child->cloneNode(false);
echo $deep->nodeName, '|', $doc->saveXML($deep), '|', $doc->saveXML($shallow), "END\n";
--EXPECT--
child|<child id="1"><inner/></child>|<child id="1"/>END
