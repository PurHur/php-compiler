<?php
/** Direct adoptNode cross-document repro (#19654 / #29853). */
$d1 = new DOMDocument();
$d1->loadXML('<a><n>t</n></a>');
$d2 = new DOMDocument();
$d2->loadXML('<b/>');
$n = $d1->documentElement->firstChild;
$a = $d2->adoptNode($n);
echo $a->nodeName, "\n";
echo $d1->saveXML($d1->documentElement), "\n";
$d2->documentElement->appendChild($a);
echo $d2->saveXML($d2->documentElement), "\n";
