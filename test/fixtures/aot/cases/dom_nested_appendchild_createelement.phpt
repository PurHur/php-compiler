--TEST--
AOT: nested DOMDocument::appendChild(createElement) must not abort (#29638)
--FILE--
<?php
declare(strict_types=1);
$d = new DOMDocument();
$d->appendChild($d->createElement('root'));
echo "nested=ok\n";
$d2 = new DOMDocument();
$r = $d2->createElement('root');
$d2->appendChild($r);
echo "split=ok\n";
$d3 = new DOMDocument();
$root = $d3->appendChild($d3->createElement('root'));
$a = $root->appendChild($d3->createElement('a'));
$b = $d3->createElement('b');
$root->replaceChild($b, $a);
echo 'replace=len=', $root->childNodes->length, ' name=', $root->firstChild->nodeName, "\n";
--EXPECT--
nested=ok
split=ok
replace=len=1 name=b
