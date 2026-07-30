<?php
declare(strict_types=1);
/**
 * Issue #25605 — importNode($d1->documentElement->firstChild, true) must keep $d2->documentElement.
 */
$d1 = new DOMDocument();
$d1->loadXML("<root><a><b>t</b></a></root>");
$d2 = new DOMDocument();
$d2->loadXML("<root/>");
$n = $d2->importNode($d1->documentElement->firstChild, true);
$d2->documentElement->appendChild($n);
echo $d2->saveXML();
