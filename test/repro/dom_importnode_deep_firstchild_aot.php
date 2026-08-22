<?php
declare(strict_types=1);
// AOT deep importNode of documentElement->firstChild must copy the source subtree
// (php-src xmlDocCopyNode), not materialize the destination loadXML root.
$d1 = new DOMDocument();
$d1->loadXML('<a><b><c>t</c></b></a>');
$d2 = new DOMDocument();
$d2->loadXML('<r/>');
$imp = $d2->importNode($d1->documentElement->firstChild, true);
$d2->documentElement->appendChild($imp);
echo $d2->saveXML($d2->documentElement), "\n";
echo 'len=', $d2->getElementsByTagName('*')->length, "\n";
