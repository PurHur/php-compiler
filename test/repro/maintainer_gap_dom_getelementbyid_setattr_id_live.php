<?php
/**
 * Repro #19870 — getElementById must track setAttribute/removeAttribute id mutations.
 * Zend: after_b=1 after_a=0 after_rm_a=0
 */
$doc = new DOMDocument();
$doc->loadHTML('<html><body><div id="a">x</div></body></html>', LIBXML_NOERROR);
$el = $doc->getElementById('a');
echo 'before=', null !== $el ? $el->textContent : 'null', "\n";
$el->setAttribute('id', 'b');
echo 'after_b=', null !== $doc->getElementById('b') ? '1' : '0', "\n";
echo 'after_a=', null !== $doc->getElementById('a') ? '1' : '0', "\n";
$el->removeAttribute('id');
echo 'after_rm_a=', null !== $doc->getElementById('a') ? '1' : '0', "\n";
