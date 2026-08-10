--TEST--
JIT: DOMDocument create/attribute null TypeError under strict_types (#29985, ext/dom/php_dom.c)
--FILE--
<?php
declare(strict_types=1);
$d = new DOMDocument();
$d->loadXML('<r a="1"/>');
$el = $d->documentElement;
$cases = [
    ['createElement', static fn () => $d->createElement(null)],
    ['createTextNode', static fn () => $d->createTextNode(null)],
    ['createAttribute', static fn () => $d->createAttribute(null)],
    ['createComment', static fn () => $d->createComment(null)],
    ['setAttribute', static fn () => $el->setAttribute(null, 'v')],
    ['getAttribute', static fn () => $el->getAttribute(null)],
];
foreach ($cases as [$name, $fn]) {
    try {
        $fn();
        echo $name, "=fail\n";
    } catch (Throwable $e) {
        echo $name, '=', get_class($e), ':', $e->getMessage(), "\n";
    }
}
--EXPECT--
createElement=TypeError:DOMDocument::createElement(): Argument #1 ($localName) must be of type string, null given
createTextNode=TypeError:DOMDocument::createTextNode(): Argument #1 ($data) must be of type string, null given
createAttribute=TypeError:DOMDocument::createAttribute(): Argument #1 ($localName) must be of type string, null given
createComment=TypeError:DOMDocument::createComment(): Argument #1 ($data) must be of type string, null given
setAttribute=TypeError:DOMElement::setAttribute(): Argument #1 ($qualifiedName) must be of type string, null given
getAttribute=TypeError:DOMElement::getAttribute(): Argument #1 ($qualifiedName) must be of type string, null given
