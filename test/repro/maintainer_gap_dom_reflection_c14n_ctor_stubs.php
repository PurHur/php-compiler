<?php

declare(strict_types=1);

// Reflection stub residual (#31849): C14N/?array, lookupNamespaceURI/?string, ctor optionals.

function dumpParam(string $cls, string $method, int $i): void
{
    $p = (new ReflectionMethod($cls, $method))->getParameters()[$i];
    $type = $p->hasType() ? (string) $p->getType() : 'none';
    $def = $p->isDefaultValueAvailable() ? var_export($p->getDefaultValue(), true) : 'n/a';
    echo $cls, '::', $method, ' arg', $i, '=', $type,
        ' opt=', ($p->isOptional() ? '1' : '0'),
        ' def=', $def, "\n";
}

dumpParam('DOMNode', 'C14N', 2);
dumpParam('DOMNode', 'C14N', 3);
dumpParam('DOMNode', 'C14NFile', 3);
dumpParam('DOMNode', 'C14NFile', 4);
dumpParam('DOMNode', 'lookupNamespaceURI', 0);
dumpParam('DOMDocument', '__construct', 0);
dumpParam('DOMDocument', '__construct', 1);
dumpParam('DOMElement', '__construct', 1);
dumpParam('DOMElement', '__construct', 2);
dumpParam('DOMText', '__construct', 0);
dumpParam('DOMComment', '__construct', 0);
dumpParam('DOMAttr', '__construct', 1);
dumpParam('DOMProcessingInstruction', '__construct', 1);

$doc = new DOMDocument();
$doc->loadXML('<r xmlns:p="urn:p"><p:a/></r>');
$el = $doc->documentElement->firstChild;
echo 'runtime_lookup_null=', var_export($el->lookupNamespaceURI(null), true), "\n";
echo 'runtime_c14n_null=', $el->C14N(false, false, null, null), "\n";
echo 'runtime_elem_ctor=', (new DOMElement('x'))->tagName, "\n";
echo 'runtime_text_ctor=', var_export((new DOMText())->data, true), "\n";
