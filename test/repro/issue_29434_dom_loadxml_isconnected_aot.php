<?php
// #29434 — AOT loadXML tree $isConnected (PHP 8.4+ profile; re-#29375)
$d = new DOMDocument();
$d->loadXML('<r><a/></r>');
$r = $d->documentElement;
$a = $r->firstChild;
echo ($r->isConnected === true ? 'root_ok' : 'root_fail'), "\n";
echo ($a->isConnected === true ? 'child_ok' : 'child_fail'), "\n";
echo ($d->isConnected === true ? 'doc_ok' : 'doc_fail'), "\n";
$d->removeChild($r);
echo ($r->isConnected === false ? 'removed' : 'removed_fail'), "\n";
