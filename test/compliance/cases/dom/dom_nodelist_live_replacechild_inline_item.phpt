--TEST--
DOM: live getElementsByTagName length after replaceChild(createElement, getElementsByTagName()->item()) (#25563)
--FILE--
<?php
$d = new DOMDocument();
$d->loadXML('<r><a/><a/><b/></r>');
$list = $d->getElementsByTagName('a');
echo 'before=', $list->length, "\n";
$d->documentElement->replaceChild(
    $d->createElement('a'),
    $d->getElementsByTagName('b')->item(0)
);
echo 'after=', $list->length, "\n";

$d2 = new DOMDocument();
$d2->loadXML('<r><a/><a/><b/></r>');
$list2 = $d2->getElementsByTagName('a');
$old = $d2->getElementsByTagName('b')->item(0);
$d2->documentElement->replaceChild($d2->createElement('a'), $old);
echo 'temp=', $list2->length, "\n";
?>
--EXPECT--
before=2
after=3
temp=3
