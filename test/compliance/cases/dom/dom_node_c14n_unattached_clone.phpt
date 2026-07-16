--TEST--
DOMNode::C14N() empty on unattached cloneNode / createElement (#19741, ext/dom/node.c)
--FILE--
<?php
$d = new DOMDocument();
$d->loadXML('<r><a x="1">t</a></r>');
$root = $d->documentElement;
echo ($root->C14N() === '<r><a x="1">t</a></r>') ? "root " : "root-fail ";
$clone = $root->cloneNode(true);
echo (null === $clone->parentNode) ? "orphan " : "parent ";
echo ('' === $clone->C14N()) ? "clone-empty " : "clone-xml ";
$d->documentElement->appendChild($clone);
echo ($clone->C14N() === '<r><a x="1">t</a></r>') ? "attached " : "attached-fail ";
$created = $d->createElement('x');
echo ('' === $created->C14N()) ? "create-empty " : "create-xml ";
$removed = $root->removeChild($root->firstChild);
echo ('' === $removed->C14N()) ? "removed-empty\n" : "removed-xml\n";
?>
--EXPECT--
root orphan clone-empty attached create-empty removed-empty
