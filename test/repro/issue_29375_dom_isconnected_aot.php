<?php
// #29375 — AOT DOMNode::$isConnected after appendChild (PHP 8.4+ profile)
$d = new DOMDocument();
$a = $d->createElement('a');
echo ($a->isConnected === false ? 'detached' : 'detached_fail'), "\n";
$d->appendChild($a);
echo ($a->isConnected === true ? 'connected' : 'connected_fail'), "\n";
echo ($d->isConnected === true ? 'doc_ok' : 'doc_fail'), "\n";
$d->removeChild($a);
echo ($a->isConnected === false ? 'removed' : 'removed_fail'), "\n";
