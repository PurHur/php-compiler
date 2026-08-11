<?php

declare(strict_types=1);

// setIdAttribute / *AttributeNS / getElementsByTagNameNS null under strict_types (#30091).
$d = new DOMDocument();
$d->loadXML('<r id="a"><c/></r>');
$el = $d->documentElement;
$cases = [
    'setIdAttribute' => static fn () => $el->setIdAttribute(null, true),
    'setIdAttributeNS' => static fn () => $el->setIdAttributeNS(null, 'id', true),
    'hasAttributeNS' => static fn () => $el->hasAttributeNS(null, null),
    'getAttributeNS' => static fn () => $el->getAttributeNS(null, null),
    'removeAttributeNS' => static fn () => $el->removeAttributeNS(null, null),
    'setAttributeNS' => static fn () => $el->setAttributeNS(null, null, 'v'),
    'getElementsByTagNameNS_local' => static fn () => $d->getElementsByTagNameNS('http://x', null),
];
foreach ($cases as $name => $fn) {
    try {
        $fn();
        echo $name, "=fail:no_throw\n";
        exit(1);
    } catch (TypeError $e) {
        echo $name, '=ok:', $e->getMessage(), "\n";
    } catch (Throwable $e) {
        echo $name, '=fail:', get_class($e), ':', $e->getMessage(), "\n";
        exit(1);
    }
}

// ?string $namespace must succeed under strict_types (Zend stub).
$len = $d->getElementsByTagNameNS(null, 'c')->length;
if (1 !== $len) {
    echo "getElementsByTagNameNS_ns=fail:len={$len}\n";
    exit(1);
}
echo "getElementsByTagNameNS_ns=ok:len={$len}\n";
