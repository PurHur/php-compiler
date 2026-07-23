<?php
$d = new DOMDocument();
$d->loadXML('<r><a/></r>');
$a = $d->documentElement->firstChild;
$d->documentElement->removeChild($a);
try {
    $d->documentElement->removeChild($a);
    echo "no throw\n";
} catch (DOMException $e) {
    echo 'msg=' . $e->getMessage() . ' code=' . $e->getCode() . "\n";
}
echo 'DOM_NOT_FOUND_ERR=' . DOM_NOT_FOUND_ERR . "\n";
