<?php

declare(strict_types=1);

// Real adoptNode requires PHP 8.3+ (#24995). Run with PHP_COMPILER_PROFILE=8.3 (or 8.4).

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
echo ($a->ownerDocument === $d2) ? "owner=d2\n" : "owner=other\n";
echo ($n === $a) ? "same-object\n" : "cloned\n";
