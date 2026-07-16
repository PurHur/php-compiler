<?php
$w = new XMLWriter();
$w->openMemory();
$w->startElement("a");
$w->endElement();
$out = $w->flush(true);
echo "type=", gettype($out), "\n";
var_export($out);
echo "\n";
