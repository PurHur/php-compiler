--TEST--
dom_import_simplexml / simplexml_import_dom Reflection stubs match php-src (#26464)
--FILE--
<?php
declare(strict_types=1);

$r = new ReflectionFunction('dom_import_simplexml');
echo 'dom_import_simplexml ret=', (string) $r->getReturnType(), "\n";
foreach ($r->getParameters() as $p) {
    echo '  ', $p->getName(), ' type=', $p->hasType() ? (string) $p->getType() : '?', ' opt=', $p->isOptional() ? 'Y' : 'N', "\n";
}

$r = new ReflectionFunction('simplexml_import_dom');
echo 'simplexml_import_dom ret=', (string) $r->getReturnType(), "\n";
foreach ($r->getParameters() as $p) {
    echo '  ', $p->getName(), ' type=', $p->hasType() ? (string) $p->getType() : '?', ' opt=', $p->isOptional() ? 'Y' : 'N';
    if ($p->isOptional() && $p->isDefaultValueAvailable()) {
        echo ' def=', var_export($p->getDefaultValue(), true);
    }
    echo "\n";
}
--EXPECT--
dom_import_simplexml ret=DOMAttr|DOMElement
  node type=object opt=N
simplexml_import_dom ret=?SimpleXMLElement
  node type=SimpleXMLElement|DOMNode opt=N
  class_name type=?string opt=Y def='SimpleXMLElement'
