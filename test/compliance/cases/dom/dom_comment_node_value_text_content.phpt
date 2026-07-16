--TEST--
stdlib DOMComment nodeValue/textContent match data (#19455, ext/dom/characterdata.c)
--FILE--
<?php
$d = new DOMDocument();
$d->loadXML("<r><!--hello--></r>");
$c = $d->documentElement->firstChild;
echo "nodeValue=", var_export($c->nodeValue, true), "\n";
echo "textContent=", var_export($c->textContent, true), "\n";
echo "data=", var_export($c->data, true), "\n";
$c2 = $d->createComment("world");
echo "created nodeValue=", var_export($c2->nodeValue, true), "\n";
echo "created textContent=", var_export($c2->textContent, true), "\n";
echo "created data=", var_export($c2->data, true), "\n";
$el = $d->documentElement;
echo "el textContent=", var_export($el->textContent, true), "\n";
echo "el nodeValue=", var_export($el->nodeValue, true), "\n";
$d2 = new DOMDocument();
$d2->loadXML("<r>a<!--x-->b</r>");
echo "mixed textContent=", var_export($d2->documentElement->textContent, true), "\n";
echo "mixed nodeValue=", var_export($d2->documentElement->nodeValue, true), "\n";
?>
--EXPECT--
nodeValue='hello'
textContent='hello'
data='hello'
created nodeValue='world'
created textContent='world'
created data='world'
el textContent=''
el nodeValue=''
mixed textContent='ab'
mixed nodeValue='ab'
