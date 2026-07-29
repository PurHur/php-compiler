<?php
/**
 * #24804 — DOMDocument::$strictErrorChecking=false → warn+false on create*;
 * setAttribute always throws; setAttributeNS honors document strict.
 * php-src: ext/dom/php_dom.c php_dom_throw_error / document.c / element.c
 */
function expect_false(string $label, $r): void
{
    echo $label, '=', ($r === false ? 'false' : 'NOT_FALSE'), "\n";
}

function expect_ex(string $label, int $code, callable $fn): void
{
    try {
        $fn();
        echo $label, "=NO_THROW\n";
    } catch (DOMException $e) {
        echo $label, '=', ($e->getCode() === $code ? 'OK' : 'code='.$e->getCode()), "\n";
    }
}

$d = new DOMDocument();
$d->strictErrorChecking = false;
expect_false('ce', $d->createElement('123bad'));
expect_false('ca', $d->createAttribute('123bad'));
expect_false('cens', $d->createElementNS('urn:x', '1:x'));

$d->loadXML('<r/>');
$el = $d->documentElement;
expect_ex('sa', 5, fn () => $el->setAttribute('123bad', 'v'));
$el->setAttributeNS('urn:x', '1:x', 'v');
echo 'sans_get=', var_export($el->getAttributeNS('urn:x', 'x'), true), "\n";

$strict = new DOMDocument();
expect_ex('ce_strict', 5, fn () => $strict->createElement('123bad'));
echo "ok\n";
