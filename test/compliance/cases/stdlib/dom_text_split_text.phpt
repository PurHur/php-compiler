--TEST--
stdlib DOMText::splitText() text node split (#17513, ext/dom/text.c)
--FILE--
<?php
$doc = new DOMDocument();
$root = $doc->createElement('root');
$doc->appendChild($root);
$text = $doc->createTextNode('hello');
$root->appendChild($text);
$tail = $text->splitText(2);
echo $text->data, "\n";
echo $tail->data, "\n";
echo (int) ($tail->previousSibling === $text), "\n";
echo (int) ($text->nextSibling === $tail), "\n";
echo $doc->saveXML($root), "\n";
$detached = $doc->createTextNode('world');
$detachedTail = $detached->splitText(2);
echo $detached->data, "\n";
echo $detachedTail->data, "\n";
echo null === $detachedTail->parentNode ? "nullparent\n" : "badparent\n";
try {
    $text->splitText(-1);
    echo "noexception\n";
} catch (ValueError $e) {
    echo $e->getMessage(), "\n";
}
var_export((new DOMDocument())->createTextNode('a')->splitText(5));
echo "\n";
?>
--EXPECT--
he
llo
1
1
<root>hello</root>
wo
rld
nullparent
DOMText::splitText(): Argument #1 ($offset) must be greater than or equal to 0
false
