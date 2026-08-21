<?php
$d = new DOMDocument();
$d->loadXML('<r><a/><b/><c/></r>');
$o = [];
foreach ($d->documentElement->childNodes as $n) {
    $o[] = $n->nodeName;
}
echo implode(',', $o), PHP_EOL;
