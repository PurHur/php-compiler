<?php
/**
 * #34646 — AOT held getElementsByTagName('*') after middle removeChild
 * (remove path uses childNodes->item, which cleared lastTagQuery).
 * php-src: ext/dom/nodelist.c; ext/dom/node.c dom_node_remove_child.
 */
$d = new DOMDocument();
$d->loadXML('<r><a/><b/><c/></r>');
$list = $d->getElementsByTagName('*');
echo 'before=', $list->length, "\n";
$d->documentElement->removeChild($d->documentElement->childNodes->item(1));
echo 'after=', $list->length, "\n";
for ($i = 0; $i < $list->length; $i++) {
    echo $list->item($i)->nodeName, ',';
}
echo "\n";
