<?php
// Repro #26765 — AOT ParentNode::append mixed element+text must match Zend/VM saveXML.
$d = new DOMDocument();
$d->loadXML('<root/>');
$r = $d->documentElement;
$r->append($d->createElement('a'), 'txt', $d->createElement('b'));
echo $d->saveXML($r), PHP_EOL;
