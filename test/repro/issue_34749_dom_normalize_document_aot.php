<?php
$d = new DOMDocument();
$d->loadXML("<r> <a/> </r>");
$d->normalizeDocument();
echo $d->documentElement->childNodes->length, "\n";
