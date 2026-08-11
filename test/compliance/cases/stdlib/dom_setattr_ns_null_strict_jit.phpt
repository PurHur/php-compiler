--TEST--
JIT: DOMElement setIdAttribute / *AttributeNS / getElementsByTagNameNS null under strict_types (#30091)
--FILE--
<?php
declare(strict_types=1);
$d = new DOMDocument();
$d->loadXML('<r id="a"><c/></r>');
$el = $d->documentElement;
$cases = [
    ['setIdAttribute', static fn () => $el->setIdAttribute(null, true)],
    ['setIdAttributeNS', static fn () => $el->setIdAttributeNS(null, 'id', true)],
    ['hasAttributeNS', static fn () => $el->hasAttributeNS(null, null)],
    ['getAttributeNS', static fn () => $el->getAttributeNS(null, null)],
    ['removeAttributeNS', static fn () => $el->removeAttributeNS(null, null)],
    ['setAttributeNS', static fn () => $el->setAttributeNS(null, null, 'v')],
    ['getElementsByTagNameNS_local', static fn () => $d->getElementsByTagNameNS('http://x', null)],
];
foreach ($cases as [$name, $fn]) {
    try {
        $fn();
        echo $name, "=fail\n";
    } catch (Throwable $e) {
        echo $name, '=', get_class($e), ':', $e->getMessage(), "\n";
    }
}
echo 'getElementsByTagNameNS_ns=', $d->getElementsByTagNameNS(null, 'c')->length, "\n";
--EXPECT--
setIdAttribute=TypeError:DOMElement::setIdAttribute(): Argument #1 ($qualifiedName) must be of type string, null given
setIdAttributeNS=TypeError:DOMElement::setIdAttributeNS(): Argument #1 ($namespace) must be of type string, null given
hasAttributeNS=TypeError:DOMElement::hasAttributeNS(): Argument #2 ($localName) must be of type string, null given
getAttributeNS=TypeError:DOMElement::getAttributeNS(): Argument #2 ($localName) must be of type string, null given
removeAttributeNS=TypeError:DOMElement::removeAttributeNS(): Argument #2 ($localName) must be of type string, null given
setAttributeNS=TypeError:DOMElement::setAttributeNS(): Argument #2 ($qualifiedName) must be of type string, null given
getElementsByTagNameNS_local=TypeError:DOMDocument::getElementsByTagNameNS(): Argument #2 ($localName) must be of type string, null given
getElementsByTagNameNS_ns=1
