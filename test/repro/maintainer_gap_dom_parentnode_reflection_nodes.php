<?php
/**
 * #25742 — ParentNode/ChildNode Reflection exposes variadic $nodes (php_dom.stub.php).
 */
$cases = [
    ['DOMDocument', 'append'],
    ['DOMDocument', 'prepend'],
    ['DOMElement', 'append'],
    ['DOMElement', 'prepend'],
    ['DOMElement', 'before'],
    ['DOMElement', 'after'],
    ['DOMElement', 'replaceWith'],
    ['DOMDocumentFragment', 'append'],
    ['DOMDocumentFragment', 'prepend'],
    ['DOMCharacterData', 'before'],
    ['DOMText', 'after'],
    ['DOMComment', 'replaceWith'],
];
foreach ($cases as $pair) {
    $rm = new ReflectionMethod($pair[0], $pair[1]);
    $p = $rm->getParameters()[0];
    echo $pair[0], '::', $pair[1], ' tot=', $rm->getNumberOfParameters(),
        ' req=', $rm->getNumberOfRequiredParameters(),
        ' name=', $p->getName(),
        ' variadic=', $p->isVariadic() ? '1' : '0',
        "\n";
}

$d = new DOMDocument();
$d->loadXML('<r/>');
$d->documentElement->append('x', $d->createElement('y'));
echo 'runtime=', $d->saveXML($d->documentElement), "\n";
