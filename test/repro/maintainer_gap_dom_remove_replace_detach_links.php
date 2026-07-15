<?php
// Maintainer gap: removeChild/replaceChild clear parent/sibling on detached node (#19240).
// VM/JIT: loadXML path. AOT: createElement/appendChild (loadXML user-script is stubby).
$d = new DOMDocument();
$root = $d->createElement('root');
$d->appendChild($root);
$a = $d->createElement('a');
$b = $d->createElement('b');
$root->appendChild($a);
$root->appendChild($b);
$root->removeChild($a);
echo 'rm_parent=', ($a->parentNode === null ? 'null' : $a->parentNode->nodeName), "\n";
echo 'rm_next=', ($a->nextSibling === null ? 'null' : $a->nextSibling->nodeName), "\n";
echo 'rm_prev=', ($a->previousSibling === null ? 'null' : $a->previousSibling->nodeName), "\n";

$d2 = new DOMDocument();
$root2 = $d2->createElement('root');
$d2->appendChild($root2);
$old = $d2->createElement('old');
$root2->appendChild($old);
$root2->replaceChild($d2->createElement('new'), $old);
echo 'rep_parent=', ($old->parentNode === null ? 'null' : $old->parentNode->nodeName), "\n";
echo 'rep_next=', ($old->nextSibling === null ? 'null' : $old->nextSibling->nodeName), "\n";
echo 'rep_prev=', ($old->previousSibling === null ? 'null' : $old->previousSibling->nodeName), "\n";
