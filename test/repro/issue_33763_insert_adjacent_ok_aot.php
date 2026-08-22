<?php
declare(strict_types=1);

$d = new DOMDocument();
$d->loadXML('<r/>');
$el = $d->documentElement;
$child = $d->createElement('c');
$ret = $el->insertAdjacentElement('beforeend', $child);
echo $ret === $child ? 'same' : 'diff', '|', $el->firstChild->nodeName, "\n";
