--TEST--
DOM attributes/childNodes property reads return distinct live wrappers (#26330)
--FILE--
<?php
$dom = new DOMDocument();
$dom->loadXML('<root><a/></root>');
$root = $dom->documentElement;

$a1 = $root->attributes;
$a2 = $root->attributes;
echo 'attr_same=', ($a1 === $a2) ? 'yes' : 'no', "\n";

$c1 = $root->childNodes;
$c2 = $root->childNodes;
echo 'child_same=', ($c1 === $c2) ? 'yes' : 'no', "\n";

$root->setAttribute('id', 'x');
echo 'attr_len=', $a1->length, "\n";

$before = $c1->length;
$root->appendChild($dom->createElement('b'));
echo 'child_len=', $c1->length, ' grew=', ($c1->length > $before) ? 'yes' : 'no', "\n";

$g1 = $dom->getElementsByTagName('a');
$g2 = $dom->getElementsByTagName('a');
echo 'get_same=', ($g1 === $g2) ? 'yes' : 'no', "\n";
--EXPECT--
attr_same=no
child_same=no
attr_len=1
child_len=2 grew=yes
get_same=no
