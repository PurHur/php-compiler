--TEST--
DOMDocument::loadHTML() honors <?xml encoding> prologue (#22022, ext/dom/document.c)
--FILE--
<?php
$d = new DOMDocument();
@$d->loadHTML('<?xml encoding="utf-8"><p id="x">café</p>');
echo 'length=', $d->getElementsByTagName('p')->length, "\n";
$p = $d->getElementsByTagName('p')->item(0);
echo 'text=', $p ? $p->textContent : '(null)', "\n";
echo 'id=', $p ? $p->getAttribute('id') : '(null)', "\n";
$hasPi = false;
for ($i = 0; $i < $d->childNodes->length; $i++) {
    $n = $d->childNodes->item($i);
    if (XML_PI_NODE === $n->nodeType && 'xml' === $n->nodeName) {
        $hasPi = true;
        break;
    }
}
echo 'pi=', $hasPi ? 'y' : 'n', "\n";
--EXPECT--
length=1
text=café
id=x
pi=y
