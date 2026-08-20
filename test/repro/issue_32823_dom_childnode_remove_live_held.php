<?php
declare(strict_types=1);
/**
 * #32823 — AOT ChildNode::remove must refresh held live childNodes (php-src nodelist.c).
 * Zend/VM: held length 2, item1=c after removing middle child.
 * Prior AOT: fresh length-0 list on parent → held_len stayed 3 with stale item1=b (re-#32774).
 */
$doc = new DOMDocument();
$doc->loadXML('<r><a/><b/><c/></r>');
$el = $doc->documentElement;
$list = $el->childNodes;
$b = $el->childNodes->item(1);
$b->remove();
echo 'held_len=', $list->length, "\n";
echo 'held0=', ($list->item(0) ? $list->item(0)->nodeName : 'null'), "\n";
echo 'held1=', ($list->item(1) ? $list->item(1)->nodeName : 'null'), "\n";
echo 'refetch_len=', $el->childNodes->length, "\n";
echo 'refetch1=', $el->childNodes->item(1)->nodeName, "\n";
