--TEST--
AOT: DOMElement::removeAttributeNode returns Attr and drops it (ext/dom/element.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
$doc = new DOMDocument();
$root = $doc->createElement('root');
$doc->appendChild($root);
$el = $doc->createElement('div');
$root->appendChild($el);
$el->setAttribute('id', 'x');
$attr = $el->getAttributeNode('id');
$removed = $el->removeAttributeNode($attr);
echo ($removed === $attr ? 'same' : 'diff'), "\n";
echo $removed->name, "\n";
echo $removed->value, "\n";
echo $el->hasAttribute('id') ? "kept\n" : "gone\n";
echo '['.$el->getAttribute('id')."]\n";
try {
    $el->removeAttributeNode(null);
    echo "null=fail\n";
} catch (TypeError $e) {
    echo "TypeError\n";
}
$orphan = $doc->createAttribute('y');
try {
    $el->removeAttributeNode($orphan);
    echo "orphan=fail\n";
} catch (DOMException $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
same
id
x
gone
[]
TypeError
Not Found Error
