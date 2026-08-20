<?php
declare(strict_types=1);
/**
 * #32784 — AOT replaceChild must refresh held live childNodes (php-src nodelist.c).
 * Zend/VM: held length 3, item1=n; AOT was item1=c then abort on item(2).
 */
$doc = new DOMDocument();
$doc->loadXML('<r><a/><b/><c/></r>');
$el = $doc->documentElement;
$list = $el->childNodes;
$old = $list->item(1);
$new = $doc->createElement('n');
$el->replaceChild($new, $old);
echo 'held_len=', $list->length, "\n";
echo 'held_item0=', ($list->item(0) ? $list->item(0)->nodeName : 'null'), "\n";
echo 'held_item1=', ($list->item(1) ? $list->item(1)->nodeName : 'null'), "\n";
echo 'held_item2=', ($list->item(2) ? $list->item(2)->nodeName : 'null'), "\n";
echo 'refetch_len=', $el->childNodes->length, "\n";
echo 'refetch_item1=', $el->childNodes->item(1)->nodeName, "\n";
