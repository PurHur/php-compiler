--TEST--
stdlib DOMNode::getNodePath() duplicate sibling index (#15125, ext/dom/node.c)
--FILE--
<?php
$doc = new DOMDocument();
$doc->loadXML('<root><child/><child/></root>');
$root = $doc->documentElement;
$paths = [];
for ($c = $root->firstChild; $c; $c = $c->nextSibling) {
    if (XML_ELEMENT_NODE === $c->nodeType) {
        $paths[] = $c->getNodePath();
    }
}
echo implode(',', $paths), "\n";
$doc2 = new DOMDocument();
$doc2->loadXML('<root><child/></root>');
echo $doc2->documentElement->firstChild->getNodePath(), "\n";
--EXPECT--
/root/child[1],/root/child[2]
/root/child
