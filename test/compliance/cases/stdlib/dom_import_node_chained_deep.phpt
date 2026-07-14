--TEST--
stdlib DOMDocument::importNode() chained PropertyFetch + deep bool (#18860, ext/dom/php_dom.c)
--FILE--
<?php
declare(strict_types=1);
if (!class_exists('DOMDocument', false)) {
    print "skip: DOMDocument not available\n";
    exit(0);
}
$d1 = new DOMDocument();
$d1->loadXML('<root><a id="1"/></root>');
$d2 = new DOMDocument();
$d2->loadXML('<other/>');
$n = $d2->importNode($d1->documentElement->firstChild, true);
echo $n->nodeName, ':', $n->getAttribute('id'), "\n";
?>
--EXPECT--
a:1
