--TEST--
xmlreader XMLReader::expand() — DOMElement from current element (#19394, ext/xmlreader/php_xmlreader.c)
--FILE--
<?php
$r = new XMLReader();
$r->XML('<r><a id="1"><b>t</b></a></r>');
$seen = false;
while ($r->read()) {
    if ($r->nodeType === XMLReader::ELEMENT && $r->name === 'a') {
        $n = $r->expand();
        echo get_class($n), "\n";
        echo 'id=', $n->getAttribute('id'), "\n";
        echo 'child=', $n->firstChild->tagName, ' text=', $n->textContent, "\n";
        $seen = true;
        break;
    }
}
echo $seen ? "ok\n" : "missing\n";

$r2 = new XMLReader();
$r2->XML('<r><e id="z"/></r>');
while ($r2->read()) {
    if ($r2->nodeType === XMLReader::ELEMENT && $r2->name === 'e') {
        $n = $r2->expand();
        echo 'empty=', get_class($n), ' id=', $n->getAttribute('id'), ' kids=', $n->childNodes->length, "\n";
        break;
    }
}

$doc = new DOMDocument();
$r3 = new XMLReader();
$r3->XML('<r><a id="1"><b>t</b></a></r>');
while ($r3->read()) {
    if ($r3->nodeType === XMLReader::ELEMENT && $r3->name === 'a') {
        $n = $r3->expand($doc);
        echo 'base=', ($n->ownerDocument === $doc ? 'yes' : 'no'), "\n";
        break;
    }
}

$r4 = new XMLReader();
try {
    $r4->expand();
    echo "no-data:ok?\n";
} catch (Throwable $e) {
    echo 'no-data=', get_class($e), "\n";
}
?>
--EXPECT--
DOMElement
id=1
child=b text=t
ok
empty=DOMElement id=z kids=0
base=yes
no-data=Error
