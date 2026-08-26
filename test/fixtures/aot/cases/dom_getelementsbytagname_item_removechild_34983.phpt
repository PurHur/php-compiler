--TEST--
AOT: getElementsByTagName item() live identity — removeChild updates held list (#34983)
--FILE--
<?php
$d = new DOMDocument();
$d->loadXML('<r><a/><a/><a/></r>');
$l = $d->getElementsByTagName('a');
echo $l->length, '|';
$fromList = $l->item(1);
$fromChild = $d->documentElement->childNodes->item(1);
echo ($fromList->parentNode ? $fromList->parentNode->nodeName : 'null'), '|';
echo ($fromList->isSameNode($fromChild) ? 'same' : 'diff'), '|';
$d->documentElement->removeChild($fromList);
echo $l->length, '|', $d->getElementsByTagName('a')->length, "\n";
--EXPECT--
3|r|same|2|2
