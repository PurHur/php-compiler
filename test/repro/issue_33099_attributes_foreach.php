<?php
$d = new DOMDocument();
$d->loadXML('<r a="1" b="2"/>');
$o = [];
foreach ($d->documentElement->attributes as $n) {
    $o[] = $n->name;
}
echo implode(',', $o), PHP_EOL;
