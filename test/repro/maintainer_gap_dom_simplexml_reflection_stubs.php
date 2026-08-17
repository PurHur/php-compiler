<?php

declare(strict_types=1);

// Reflection residual (#31887): DOM schema/xinclude/create* + SimpleXML children named args.

function dumpParam(string $cls, string $method, int $i): void
{
    $p = (new ReflectionMethod($cls, $method))->getParameters()[$i];
    $type = $p->hasType() ? (string) $p->getType() : 'none';
    $def = 'n/a';
    try {
        if ($p->isDefaultValueAvailable()) {
            $def = var_export($p->getDefaultValue(), true);
        }
    } catch (Throwable $e) {
        $def = 'unavail';
    }
    echo $cls, '::', $method, ' arg', $i, ' name=', $p->getName(),
        ' type=', $type,
        ' opt=', ($p->isOptional() ? '1' : '0'),
        ' def=', $def, "\n";
}

dumpParam('DOMDocument', 'schemaValidate', 1);
dumpParam('DOMDocument', 'schemaValidateSource', 1);
dumpParam('DOMDocument', 'xinclude', 0);
dumpParam('DOMDocument', 'createElement', 1);
dumpParam('DOMDocument', 'createElementNS', 2);
dumpParam('DOMXPath', 'registerPhpFunctions', 0);
dumpParam('DOMImplementation', 'createDocument', 1);
$rm = new ReflectionMethod('DOMImplementation', 'createDocumentType');
echo 'createDocumentType req=', $rm->getNumberOfRequiredParameters(), '/', $rm->getNumberOfParameters(), "\n";
dumpParam('DOMImplementation', 'createDocumentType', 0);
dumpParam('DOMImplementation', 'createDocumentType', 1);
dumpParam('SimpleXMLElement', 'asXML', 0);
dumpParam('SimpleXMLElement', 'addChild', 1);
dumpParam('SimpleXMLElement', 'addChild', 2);
dumpParam('SimpleXMLElement', 'addAttribute', 2);
dumpParam('SimpleXMLElement', 'children', 0);
dumpParam('SimpleXMLElement', 'children', 1);
dumpParam('SimpleXMLElement', 'attributes', 0);
dumpParam('SimpleXMLElement', 'getDocNamespaces', 1);

$xml = '<r xmlns:p="urn:p"><a>1</a><p:b>2</p:b></r>';
$s = new SimpleXMLElement($xml);
echo 'named_nsor=', $s->children(namespaceOrPrefix: 'p', isPrefix: true)->count(), "\n";
try {
    $s->children(ns: 'p', is_prefix: true);
    echo "legacy-ns-ok\n";
} catch (Throwable $e) {
    echo "legacy-ns-reject\n";
}

$impl = new DOMImplementation();
try {
    $impl->createDocumentType();
    echo "createDocumentType0-ok\n";
} catch (Throwable $e) {
    echo 'createDocumentType0=', get_class($e), "\n";
}
$el = (new DOMDocument())->createElement('x');
echo 'createElement_value=', var_export($el->nodeValue, true), "\n";
