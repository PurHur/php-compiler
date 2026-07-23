<?php
$d1 = new DOMDocument();
$d1->loadXML('<r/>');
$d2 = new DOMDocument();
$d2->loadXML('<r/>');
try {
    $d2->documentElement->appendChild($d1->createElement('x'));
    echo "no throw\n";
} catch (DOMException $e) {
    echo 'code=' . $e->getCode() . ' msg=' . $e->getMessage() . "\n";
}
echo 'const=' . DOM_WRONG_DOCUMENT_ERR . "\n";
