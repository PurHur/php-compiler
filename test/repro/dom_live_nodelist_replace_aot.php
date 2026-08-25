<?php
/**
 * #34590 — held getElementsByTagName after replaceChild via parentNode.
 * Zend: length 3→2, item0=a. AOT: parentNode null on middle child → abort;
 * or via documentElement: length stays 3.
 */
$d = new DOMDocument();
$d->loadXML('<r><a/><a/><a/></r>');
$list = $d->getElementsByTagName('a');
echo 'before=', $list->length, "\n";
$old = $list->item(1);
$new = $d->createElement('b');
$old->parentNode->replaceChild($new, $old);
echo 'after=', $list->length, ' item0=', $list->item(0)->tagName, "\n";
