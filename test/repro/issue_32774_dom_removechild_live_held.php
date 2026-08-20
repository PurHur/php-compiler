<?php
declare(strict_types=1);
/**
 * #32774 — AOT removeChild must update held live childNodes (php-src nodelist.c).
 * Zend/VM: held length 2, item0=b; refetch length 2.
 */
$doc = new DOMDocument();
$doc->loadXML('<r><a/><b/><c/></r>');
$list = $doc->documentElement->childNodes;
echo 'held_before=', $list->length, "\n";
$doc->documentElement->removeChild($list->item(0));
echo 'held_after=', $list->length, "\n";
echo 'refetch=', $doc->documentElement->childNodes->length, "\n";
echo 'held_item0=', ($list->item(0) ? $list->item(0)->nodeName : 'null'), "\n";
echo 'refetch_item0=', $doc->documentElement->childNodes->item(0)->nodeName, "\n";
