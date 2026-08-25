<?php
/**
 * #34804 — ChildNode::replaceWith(self) / args that include the receiver.
 * Zend: fragment algorithm (dom_child_replace_with); identity-only is a no-op.
 * List via childNodes (thin-AOT nextSibling after loadXML is not always linked).
 */

// self
$doc = new DOMDocument();
$doc->loadXML('<r><a/><b/><c/></r>');
$r = $doc->documentElement;
$c = $r->childNodes->item(2);
$c->replaceWith($c);
$names = [];
$list = $r->childNodes;
for ($i = 0; $i < $list->length; $i++) {
    $names[] = $list->item($i)->nodeName;
}
echo 'self=', implode(',', $names), ' parent=', ($c->parentNode ? 'r' : 'DETACHED'), "\n";

// self,x
$doc = new DOMDocument();
$doc->loadXML('<r><a/><b/><c/></r>');
$r = $doc->documentElement;
$c = $r->childNodes->item(2);
$c->replaceWith($c, 'x');
$names = [];
$list = $r->childNodes;
for ($i = 0; $i < $list->length; $i++) {
    $names[] = $list->item($i)->nodeName;
}
echo 'self,x=', implode(',', $names), ' parent=', ($c->parentNode ? 'r' : 'DETACHED'), "\n";

// x,self
$doc = new DOMDocument();
$doc->loadXML('<r><a/><b/><c/></r>');
$r = $doc->documentElement;
$c = $r->childNodes->item(2);
$c->replaceWith('x', $c);
$names = [];
$list = $r->childNodes;
for ($i = 0; $i < $list->length; $i++) {
    $names[] = $list->item($i)->nodeName;
}
echo 'x,self=', implode(',', $names), ' parent=', ($c->parentNode ? 'r' : 'DETACHED'), "\n";

// self,a
$doc = new DOMDocument();
$doc->loadXML('<r><a/><b/><c/></r>');
$r = $doc->documentElement;
$a = $r->childNodes->item(0);
$c = $r->childNodes->item(2);
$c->replaceWith($c, $a);
$names = [];
$list = $r->childNodes;
for ($i = 0; $i < $list->length; $i++) {
    $names[] = $list->item($i)->nodeName;
}
echo 'self,a=', implode(',', $names), ' parent=', ($c->parentNode ? 'r' : 'DETACHED'), "\n";

// a,self
$doc = new DOMDocument();
$doc->loadXML('<r><a/><b/><c/></r>');
$r = $doc->documentElement;
$a = $r->childNodes->item(0);
$c = $r->childNodes->item(2);
$c->replaceWith($a, $c);
$names = [];
$list = $r->childNodes;
for ($i = 0; $i < $list->length; $i++) {
    $names[] = $list->item($i)->nodeName;
}
echo 'a,self=', implode(',', $names), ' parent=', ($c->parentNode ? 'r' : 'DETACHED'), "\n";

// empty
$doc = new DOMDocument();
$doc->loadXML('<r><a/><b/><c/></r>');
$r = $doc->documentElement;
$c = $r->childNodes->item(2);
$c->replaceWith();
$names = [];
$list = $r->childNodes;
for ($i = 0; $i < $list->length; $i++) {
    $names[] = $list->item($i)->nodeName;
}
echo 'empty=', implode(',', $names), ' parent=', ($c->parentNode ? 'r' : 'DETACHED'), "\n";

// insertBefore($n,$n) must still throw
$doc = new DOMDocument();
$doc->loadXML('<r><a/></r>');
$a = $doc->documentElement->firstChild;
try {
    $doc->documentElement->insertBefore($a, $a);
    echo "insertBefore_self=OK\n";
} catch (Throwable $e) {
    echo 'insertBefore_self=ERR:', get_class($e), ':', $e->getMessage(), "\n";
}
