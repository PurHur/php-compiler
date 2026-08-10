<?php

declare(strict_types=1);

/**
 * #29638 — AOT nested Document::appendChild(createElement(...)) must not abort.
 * Split form is the control (already green).
 */

$d = new DOMDocument();
$d->appendChild($d->createElement('root'));
echo "nested=ok\n";

$d2 = new DOMDocument();
$r = $d2->createElement('root');
$d2->appendChild($r);
echo "split=ok name=", $r->nodeName, "\n";

$d3 = new DOMDocument();
$root = $d3->appendChild($d3->createElement('root'));
$a = $root->appendChild($d3->createElement('a'));
$b = $d3->createElement('b');
$root->replaceChild($b, $a);
echo 'replace=len=', $root->childNodes->length, ' name=', $root->firstChild->nodeName,
    ' parent=', ($a->parentNode === null ? 'null' : 'set'), "\n";
