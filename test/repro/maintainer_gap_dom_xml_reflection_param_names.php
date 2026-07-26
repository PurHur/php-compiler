<?php

declare(strict_types=1);

/**
 * #23391 — DOM/XMLReader Reflection param names + named args (php-src stubs).
 *
 * Keep this top-level and foreach-free so JIT can compile the Reflection path.
 */

$rm = new ReflectionMethod('DOMDocument', 'createElement');
if ([$rm->getParameters()[0]->getName(), $rm->getParameters()[1]->getName()] !== ['localName', 'value']) {
    fwrite(STDERR, "createElement params\n");
    exit(1);
}
$rm = new ReflectionMethod('DOMDocument', 'getElementById');
if ($rm->getParameters()[0]->getName() !== 'elementId') {
    fwrite(STDERR, "getElementById params\n");
    exit(1);
}
$rm = new ReflectionMethod('DOMDocument', 'getElementsByTagName');
if ($rm->getParameters()[0]->getName() !== 'qualifiedName') {
    fwrite(STDERR, "getElementsByTagName params\n");
    exit(1);
}
$rm = new ReflectionMethod('DOMDocument', 'importNode');
if ([$rm->getParameters()[0]->getName(), $rm->getParameters()[1]->getName()] !== ['node', 'deep']) {
    fwrite(STDERR, "importNode params\n");
    exit(1);
}
$rm = new ReflectionMethod('DOMDocument', 'loadHTML');
if ([$rm->getParameters()[0]->getName(), $rm->getParameters()[1]->getName()] !== ['source', 'options']) {
    fwrite(STDERR, "loadHTML params\n");
    exit(1);
}
$rm = new ReflectionMethod('DOMDocument', 'loadHTMLFile');
if ([$rm->getParameters()[0]->getName(), $rm->getParameters()[1]->getName()] !== ['filename', 'options']) {
    fwrite(STDERR, "loadHTMLFile params\n");
    exit(1);
}
$rm = new ReflectionMethod('DOMNode', 'appendChild');
if ($rm->getParameters()[0]->getName() !== 'node') {
    fwrite(STDERR, "appendChild params\n");
    exit(1);
}
$rm = new ReflectionMethod('DOMNode', 'insertBefore');
if ([$rm->getParameters()[0]->getName(), $rm->getParameters()[1]->getName()] !== ['node', 'child']) {
    fwrite(STDERR, "insertBefore params\n");
    exit(1);
}
$rm = new ReflectionMethod('DOMElement', 'setAttribute');
if ([$rm->getParameters()[0]->getName(), $rm->getParameters()[1]->getName()] !== ['qualifiedName', 'value']) {
    fwrite(STDERR, "setAttribute params\n");
    exit(1);
}
$rm = new ReflectionMethod('DOMXPath', 'query');
if ([
    $rm->getParameters()[0]->getName(),
    $rm->getParameters()[1]->getName(),
    $rm->getParameters()[2]->getName(),
] !== ['expression', 'contextNode', 'registerNodeNS']) {
    fwrite(STDERR, "query params\n");
    exit(1);
}
$rm = new ReflectionMethod('DOMXPath', 'registerNamespace');
if ([$rm->getParameters()[0]->getName(), $rm->getParameters()[1]->getName()] !== ['prefix', 'namespace']) {
    fwrite(STDERR, "registerNamespace params\n");
    exit(1);
}
$rm = new ReflectionMethod('XMLReader', 'open');
if ([
    $rm->getParameters()[0]->getName(),
    $rm->getParameters()[1]->getName(),
    $rm->getParameters()[2]->getName(),
] !== ['uri', 'encoding', 'flags']) {
    fwrite(STDERR, "XMLReader::open params\n");
    exit(1);
}
$rm = new ReflectionMethod('XMLReader', 'XML');
if ([
    $rm->getParameters()[0]->getName(),
    $rm->getParameters()[1]->getName(),
    $rm->getParameters()[2]->getName(),
] !== ['source', 'encoding', 'flags']) {
    fwrite(STDERR, "XMLReader::XML params\n");
    exit(1);
}

$doc = new DOMDocument();
$el = $doc->createElement(localName: 'x');
if ($el->tagName !== 'x') {
    fwrite(STDERR, "createElement named failed\n");
    exit(1);
}
$doc->getElementsByTagName(qualifiedName: 'x');
$doc->appendChild(node: $el);
if (!$doc->loadHTML(source: '<p>y</p>', options: 0)) {
    fwrite(STDERR, "loadHTML named failed\n");
    exit(1);
}
$el->setAttribute(qualifiedName: 'id', value: 'a');
if ($el->getAttribute(qualifiedName: 'id') !== 'a') {
    fwrite(STDERR, "setAttribute/getAttribute named failed\n");
    exit(1);
}

$xpath = new DOMXPath(document: $doc);
$xpath->registerNamespace(prefix: 'a', namespace: 'urn:a');

try {
    $doc->createElement(name: 'z');
    fwrite(STDERR, "createElement(name:) should be rejected\n");
    exit(1);
} catch (Throwable $e) {
    if (!str_contains($e->getMessage(), 'Unknown named parameter')) {
        fwrite(STDERR, "unexpected: ".$e->getMessage()."\n");
        exit(1);
    }
}

$rm = new ReflectionMethod('DOMDocument', 'loadHTML');
$p0 = $rm->getParameters()[0];
$p1 = $rm->getParameters()[1];
if ($p0->isOptional() || !$p1->isOptional()) {
    fwrite(STDERR, "loadHTML optionality mismatch\n");
    exit(1);
}

echo "ok\n";
