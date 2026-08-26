<?php
$d = new DOMDocument();
$d->loadXML('<r a="1"/>');
$a = $d->documentElement->getAttributeNode('a');
echo $a->ownerElement->nodeName, '|';
echo ($a->parentNode === null ? 'null' : $a->parentNode->nodeName), '|';
echo ($a->parentNode === $a->ownerElement) ? 'same' : 'diff', "\n";
