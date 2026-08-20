<?php
declare(strict_types=1);
/**
 * #32846 — AOT ParentNode::replaceChildren must refresh held childNodes in place.
 *
 * PROFILE=8.4 (replaceChildren is PHP 8.3+). Prior AOT allocated a fresh NodeList
 * via syncChildNodesLengthSlot — held pins stayed a/b; refetch item(0) SIGSEGV.
 *
 * Run:
 *   PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/issue_32846_dom_replacechildren_live_held.php
 *   PHP_COMPILER_PROFILE=8.4 PHP_COMPILER_HELPER_RUNTIME_O=0 php bin/compile.php -o /tmp/rc.bin \
 *     test/repro/issue_32846_dom_replacechildren_live_held.php && /tmp/rc.bin
 */
$doc = new DOMDocument();
$doc->loadXML('<r><a/><b/></r>');
$el = $doc->documentElement;
$list = $el->childNodes;
echo 'before_held_len=', $list->length, "\n";
$el->replaceChildren($doc->createElement('c'), $doc->createElement('d'));
echo 'after_held_len=', $list->length, "\n";
for ($i = 0; $i < $list->length; $i++) {
    $n = $list->item($i);
    echo 'held', $i, '=', ($n ? $n->nodeName : 'null'), "\n";
}
echo 'refetch_len=', $el->childNodes->length, "\n";
for ($i = 0; $i < $el->childNodes->length; $i++) {
    echo 'refetch', $i, '=', $el->childNodes->item($i)->nodeName, "\n";
}

$doc2 = new DOMDocument();
$doc2->loadXML('<r><a/><b/></r>');
$el2 = $doc2->documentElement;
$list2 = $el2->childNodes;
$el2->replaceChildren();
echo 'empty_held_len=', $list2->length, "\n";
echo 'empty_refetch_len=', $el2->childNodes->length, "\n";
