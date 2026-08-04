<?php

/**
 * #27480 — AOT DOMNode::replaceChild after appendChild return must orphan old child.
 * Expect: len=1 name=b parent=null
 */
$d = new DOMDocument();
$r = $d->appendChild($d->createElement('root'));
$a = $r->appendChild($d->createElement('a'));
$b = $d->createElement('b');
$r->replaceChild($b, $a);
echo 'len=', $r->childNodes->length, ' name=', $r->firstChild->nodeName,
    ' parent=', ($a->parentNode === null ? 'null' : 'set'), "\n";
