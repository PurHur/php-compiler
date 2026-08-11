<?php
// #30271 — foreign DOMDocument mutation must be Wrong Document Error (code 4).
$d = new DOMDocument();
$d->loadXML('<r><a/></r>');
$other = new DOMDocument();

foreach (['appendChild', 'insertBefore', 'replaceChild'] as $op) {
    try {
        if ('appendChild' === $op) {
            $d->documentElement->appendChild($other);
        } elseif ('insertBefore' === $op) {
            $d->documentElement->insertBefore($other, $d->documentElement->firstChild);
        } else {
            $d->documentElement->replaceChild($other, $d->documentElement->firstChild);
        }
        echo "$op NO_THROW\n";
    } catch (DOMException $e) {
        echo "$op code=", $e->getCode(), ' msg=', $e->getMessage(), "\n";
    }
}

try {
    $d->documentElement->appendChild($d);
    echo "same NO_THROW\n";
} catch (DOMException $e) {
    echo 'same code=', $e->getCode(), ' msg=', $e->getMessage(), "\n";
}
