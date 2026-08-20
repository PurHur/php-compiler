<?php
declare(strict_types=1);
/**
 * #32784 — AOT replaceChild must refresh held live childNodes (php-src nodelist.c).
 * Zend/VM: held length 3, item1=n; refetch item1=n.
 */
$doc = new DOMDocument();
$doc->loadXML('<r><a/><b/><c/></r>');
$el = $doc->documentElement;
$list = $el->childNodes;
$n = $doc->createElement('n');
$el->replaceChild($n, $list->item(1));
echo 'held_len=', $list->length, "\n";
echo 'held0=', ($list->item(0) ? $list->item(0)->nodeName : 'null'), "\n";
echo 'held1=', ($list->item(1) ? $list->item(1)->nodeName : 'null'), "\n";
echo 'held2=', ($list->item(2) ? $list->item(2)->nodeName : 'null'), "\n";
echo 'refetch_len=', $el->childNodes->length, "\n";
echo 'refetch1=', $el->childNodes->item(1)->nodeName, "\n";
