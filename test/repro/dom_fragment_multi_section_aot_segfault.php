<?php
/**
 * AOT: many DocumentFragment mutations in one main — intermittent SIGSEGV after
 * c:main_before_php (heap / lowering under large CFG). Isolated single-op
 * repros (#33312/#33322/#33327) stay green; this multi-section script fails
 * ~12/12 under thin AOT while Zend/VM match.
 */
echo "=== frag_replaceChild ===\n";
$d = new DOMDocument();
$r = $d->appendChild($d->createElement('r'));
$a = $r->appendChild($d->createElement('a'));
$b = $r->appendChild($d->createElement('b'));
$f = $d->createDocumentFragment();
$f->appendChild($d->createElement('x'));
$f->appendChild($d->createElement('y'));
$r->replaceChild($f, $a);
echo 'len='.$r->childNodes->length.' xml='.$d->saveXML($r)."\n";

echo "=== frag_insertBefore_first ===\n";
$d = new DOMDocument();
$r = $d->appendChild($d->createElement('r'));
$b = $r->appendChild($d->createElement('b'));
$f = $d->createDocumentFragment();
$f->appendChild($d->createElement('x'));
$f->appendChild($d->createElement('y'));
$r->insertBefore($f, $b);
echo 'len='.$r->childNodes->length.' xml='.$d->saveXML($r)."\n";

echo "=== frag_insertBefore_middle ===\n";
$d = new DOMDocument();
$r = $d->appendChild($d->createElement('r'));
$a = $r->appendChild($d->createElement('a'));
$b = $r->appendChild($d->createElement('b'));
$f = $d->createDocumentFragment();
$f->appendChild($d->createElement('x'));
$f->appendChild($d->createElement('y'));
$r->insertBefore($f, $b);
echo 'len='.$r->childNodes->length.' xml='.$d->saveXML($r)."\n";

echo "=== frag_appendChild ===\n";
$d = new DOMDocument();
$r = $d->appendChild($d->createElement('r'));
$r->appendChild($d->createElement('a'));
$f = $d->createDocumentFragment();
$f->appendChild($d->createElement('x'));
$f->appendChild($d->createElement('y'));
$r->appendChild($f);
echo 'len='.$r->childNodes->length.' xml='.$d->saveXML($r)."\n";

echo "=== removeChild_then_frag_append ===\n";
$d = new DOMDocument();
$r = $d->appendChild($d->createElement('r'));
$a = $r->appendChild($d->createElement('a'));
$r->appendChild($d->createElement('b'));
$r->removeChild($a);
$f = $d->createDocumentFragment();
$f->appendChild($d->createElement('x'));
$r->appendChild($f);
echo 'len='.$r->childNodes->length.' xml='.$d->saveXML($r)."\n";

echo "=== importNode_deep_append ===\n";
$d1 = new DOMDocument();
$d1->loadXML('<r><a><b/></a></r>');
$d2 = new DOMDocument();
$r2 = $d2->appendChild($d2->createElement('root'));
$imp = $d2->importNode($d1->documentElement->firstChild, true);
$r2->appendChild($imp);
echo 'len='.$r2->childNodes->length.' xml='.$d2->saveXML($r2)."\n";

echo "=== getElementById_after_setIdAttribute ===\n";
$d = new DOMDocument();
$d->loadXML('<r><a id="x">t</a></r>');
$el = $d->documentElement->firstChild;
$el->setIdAttribute('id', true);
$got = $d->getElementById('x');
echo 'got='.($got ? $got->tagName : 'null')."\n";

echo "=== live_nodelist_after_append ===\n";
$d = new DOMDocument();
$r = $d->appendChild($d->createElement('r'));
$list = $r->childNodes;
$r->appendChild($d->createElement('a'));
$r->appendChild($d->createElement('b'));
echo 'len='.$list->length.' i0='.$list->item(0)->tagName.' i1='.$list->item(1)->tagName."\n";

echo "=== replaceChild_text ===\n";
$d = new DOMDocument();
$r = $d->appendChild($d->createElement('r'));
$t = $r->appendChild($d->createTextNode('old'));
$r->replaceChild($d->createTextNode('new'), $t);
echo 'xml='.$d->saveXML($r)."\n";

echo "=== null_appendChild_typeerror ===\n";
$d = new DOMDocument();
$r = $d->appendChild($d->createElement('r'));
try {
    $r->appendChild(null);
    echo "no throw\n";
} catch (TypeError $e) {
    echo "TypeError ok\n";
}

echo "=== frag_replaceChild_only_child ===\n";
$d = new DOMDocument();
$r = $d->appendChild($d->createElement('r'));
$a = $r->appendChild($d->createElement('a'));
$f = $d->createDocumentFragment();
$f->appendChild($d->createElement('x'));
$f->appendChild($d->createElement('y'));
$r->replaceChild($f, $a);
echo 'len='.$r->childNodes->length.' xml='.$d->saveXML($r)."\n";

echo "=== empty_frag_replaceChild ===\n";
$d = new DOMDocument();
$r = $d->appendChild($d->createElement('r'));
$a = $r->appendChild($d->createElement('a'));
$b = $r->appendChild($d->createElement('b'));
$f = $d->createDocumentFragment();
$r->replaceChild($f, $a);
echo 'len='.$r->childNodes->length.' xml='.$d->saveXML($r)."\n";

echo "=== frag_replaceChild_last ===\n";
$d = new DOMDocument();
$r = $d->appendChild($d->createElement('r'));
$a = $r->appendChild($d->createElement('a'));
$b = $r->appendChild($d->createElement('b'));
$f = $d->createDocumentFragment();
$f->appendChild($d->createElement('x'));
$r->replaceChild($f, $b);
echo 'len='.$r->childNodes->length.' xml='.$d->saveXML($r)."\n";
