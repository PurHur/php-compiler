<?php
$d = new DOMDocument();
$d->loadXML('<r><a/><b/></r>');
$r = $d->documentElement;
$a = $r->firstChild;
$b = $a->nextSibling;
$r->textContent = 'z';
echo 'a_parent_null=', ($a->parentNode === null ? 'true' : 'false'), "\n";
try {
    $b->parentNode;
    echo "b_parent_null=true\n";
} catch (Error $e) {
    echo 'b_parent_err=', $e->getMessage(), "\n";
}
echo 'kids=', $r->childNodes->length, "\n";
echo 'text=', $r->textContent, "\n";
echo 'xml=', trim($d->saveXML($r)), "\n";
