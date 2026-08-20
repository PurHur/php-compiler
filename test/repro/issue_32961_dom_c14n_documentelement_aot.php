<?php
$d = new DOMDocument();
$d->loadXML('<r a="1"/>');
$el = $d->documentElement;
echo $el->C14N();
echo "\n";
