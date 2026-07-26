--TEST--
DOM/XMLReader Reflection param names match php-src stubs (#23391)
--FILE--
<?php
$rm = new ReflectionMethod('DOMDocument', 'createElement');
echo $rm->getParameters()[0]->getName(), ',', $rm->getParameters()[1]->getName(), "\n";
$rm = new ReflectionMethod('DOMDocument', 'loadHTML');
echo $rm->getParameters()[0]->getName(), ',', $rm->getParameters()[1]->getName(), "\n";
$rm = new ReflectionMethod('DOMNode', 'appendChild');
echo $rm->getParameters()[0]->getName(), "\n";
$rm = new ReflectionMethod('XMLReader', 'open');
echo $rm->getParameters()[0]->getName(), ',', $rm->getParameters()[1]->getName(), ',', $rm->getParameters()[2]->getName(), "\n";
$doc = new DOMDocument();
$el = $doc->createElement(localName: 'x');
$doc->appendChild(node: $el);
$el->setAttribute(qualifiedName: 'id', value: 'a');
echo $el->getAttribute(qualifiedName: 'id'), "\n";
try {
    $doc->createElement(name: 'z');
    echo "bad\n";
} catch (Throwable $e) {
    echo "reject\n";
}
echo "ok\n";
--EXPECT--
localName,value
source,options
node
uri,encoding,flags
a
reject
ok
