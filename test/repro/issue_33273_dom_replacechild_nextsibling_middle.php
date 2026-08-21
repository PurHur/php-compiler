<?php
declare(strict_types=1);

/**
 * #33273 — AOT replaceChild via firstChild->nextSibling must update saveXML at the middle index.
 * Zend/VM: <r><a/><x/><c/></r> + held list a,x,c. Was AOT saveXML <r><x/><b/><c/></r>.
 */
$doc = new DOMDocument();
$doc->loadXML('<r><a/><b/><c/></r>');
$r = $doc->documentElement;
$list = $r->childNodes;
$old = $r->firstChild->nextSibling; // b
$neu = $doc->createElement('x');
$r->replaceChild($neu, $old);
echo 'len=', $list->length, "\n";
for ($i = 0; $i < $list->length; $i++) {
    echo $list->item($i)->nodeName, "\n";
}
echo $doc->saveXML($r), "\n";
