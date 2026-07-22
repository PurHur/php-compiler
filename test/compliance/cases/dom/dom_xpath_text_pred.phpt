--TEST--
DOMXPath query predicates text()= / contains(text(),) match Zend (#22008, ext/dom/xpath.c)
--SKIPIF--
<?php
if (!class_exists('DOMXPath', false)) {
    print "skip: DOMXPath not available\n";
}
?>
--FILE--
<?php
$doc = new DOMDocument();
$doc->loadXML("<r><a>hello</a><b>x</b><c>O'Brien</c></r>");
$xp = new DOMXPath($doc);
echo 'text=', $xp->query("//a[text()='hello']")->length, "\n";
echo 'contains=', $xp->query("//a[contains(text(),'ell')]")->length, "\n";
echo 'plain=', $xp->query('//a')->length, "\n";
echo 'miss=', $xp->query("//a[text()='nope']")->length, "\n";
echo 'obrien=', $xp->query("//c[text()=\"O'Brien\"]")->length, "\n";
echo 'child=', $xp->query("/r/a[text()='hello']")->length, "\n";
?>
--EXPECT--
text=1
contains=1
plain=1
miss=0
obrien=1
child=1
