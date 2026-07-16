--TEST--
AOT: DOMComment nodeValue/textContent/data after loadXML + createComment (#19455)
--FILE--
<?php
$d = new DOMDocument();
$d->loadXML("<r><!--hello--></r>");
$c = $d->documentElement->firstChild;
echo "nodeValue=", $c->nodeValue, "\n";
echo "textContent=", $c->textContent, "\n";
echo "data=", $c->data, "\n";
$c2 = $d->createComment("world");
echo "created nodeValue=", $c2->nodeValue, "\n";
echo "created textContent=", $c2->textContent, "\n";
echo "created data=", $c2->data, "\n";
?>
--EXPECT--
nodeValue=hello
textContent=hello
data=hello
created nodeValue=world
created textContent=world
created data=world
