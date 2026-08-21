<?php
// AOT: foreach over childNodes after loadXML (#33082).
$d = new DOMDocument();
$d->loadXML('<r><a/><b/><c/></r>');
$out = [];
foreach ($d->documentElement->childNodes as $n) {
    $out[] = $n->nodeName;
}
echo implode(',', $out), "\n";
