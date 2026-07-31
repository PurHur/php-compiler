--TEST--
DOM ParentNode/ChildNode Reflection variadic $nodes (#25742, ext/dom/php_dom.stub.php)
--FILE--
<?php
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
--EXPECT--
DOMDocument::append tot=1 req=0 name=nodes variadic=1
DOMDocument::prepend tot=1 req=0 name=nodes variadic=1
DOMElement::append tot=1 req=0 name=nodes variadic=1
DOMElement::prepend tot=1 req=0 name=nodes variadic=1
DOMElement::before tot=1 req=0 name=nodes variadic=1
DOMElement::after tot=1 req=0 name=nodes variadic=1
DOMElement::replaceWith tot=1 req=0 name=nodes variadic=1
DOMDocumentFragment::append tot=1 req=0 name=nodes variadic=1
DOMDocumentFragment::prepend tot=1 req=0 name=nodes variadic=1
DOMCharacterData::before tot=1 req=0 name=nodes variadic=1
DOMText::after tot=1 req=0 name=nodes variadic=1
DOMComment::replaceWith tot=1 req=0 name=nodes variadic=1
runtime=<r>x<y/></r>
