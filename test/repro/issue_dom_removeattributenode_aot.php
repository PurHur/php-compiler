<?php
/**
 * AOT: DOMElement::removeAttributeNode must return the Attr and drop it (ext/dom/element.c).
 *
 * Sequential try/catch: thin-AOT does not unwind TypeError out of compiled closures.
 */
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
