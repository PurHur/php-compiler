--TEST--
DOM ReflectionClass method name casing matches Zend (#21283, ext/reflection + ext/dom)
--FILE--
<?php
declare(strict_types=1);

$ref = new ReflectionClass('DOMElement');
echo $ref->getMethod('appendChild')->getName(), "\n";
echo $ref->getMethod('appendchild')->getName(), "\n";
echo $ref->getMethod('getLineNo')->getName(), "\n";
echo $ref->getMethod('getlineno')->getName(), "\n";

$exact = false;
$line = false;
foreach ($ref->getMethods() as $m) {
    if ($m->getName() === 'appendChild') {
        $exact = true;
    }
    if ($m->getName() === 'getLineNo') {
        $line = true;
    }
}
echo $exact ? "appendChild_ok\n" : "appendChild_missing\n";
echo $line ? "getLineNo_ok\n" : "getLineNo_missing\n";

$rm = new ReflectionMethod('DOMNode', 'appendchild');
echo $rm->getName(), "\n";
--EXPECT--
appendChild
appendChild
getLineNo
getLineNo
appendChild_ok
getLineNo_ok
appendChild
