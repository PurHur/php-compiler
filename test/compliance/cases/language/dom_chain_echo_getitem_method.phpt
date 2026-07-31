--TEST--
Language: toplevel echo of getElementsByTagName()->item()->method() keeps chain temps (#25842)
--FILE--
<?php
$d = new DOMDocument();
$d->loadXML("<r>\n<a id=\"x\"/>\n</r>");
echo $d->getElementsByTagName('a')->item(0)->getLineNo(), "\n";
echo $d->getElementsByTagName('a')->item(0)->getAttribute('id'), "\n";
echo $d->getElementsByTagName('a')->item(0)->nodeName, "\n";
$el = $d->getElementsByTagName('a')->item(0);
echo $el->getLineNo(), "\n";
function f($d) {
    return $d->getElementsByTagName('a')->item(0)->getLineNo();
}
echo f($d), "\n";
?>
--EXPECT--
2
x
a
2
2
