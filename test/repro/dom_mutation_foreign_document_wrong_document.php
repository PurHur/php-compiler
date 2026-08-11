<?php
// #30271 / #30274 — foreign DOMDocument mutation must be Wrong Document Error (code 4).
// Use fwrite(STDOUT) so thin-AOT does not need ValueEcho helper objects.
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
        fwrite(STDOUT, $op." NO_THROW\n");
    } catch (DOMException $e) {
        fwrite(STDOUT, $op.' code='.$e->getCode()."\n");
    }
}

try {
    $d->documentElement->appendChild($d);
    fwrite(STDOUT, "same NO_THROW\n");
} catch (DOMException $e) {
    fwrite(STDOUT, 'same code='.$e->getCode()."\n");
}
