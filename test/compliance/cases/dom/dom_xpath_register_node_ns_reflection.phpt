--TEST--
DOMXPath Reflection registerNodeNS default true + contextNode ?DOMNode (#31348)
--FILE--
<?php
foreach (['__construct', 'query', 'evaluate'] as $method) {
    $rm = new ReflectionMethod('DOMXPath', $method);
    foreach ($rm->getParameters() as $p) {
        if ($p->getName() === 'registerNodeNS') {
            echo $method, ' registerNodeNS type=', $p->hasType() ? (string) $p->getType() : 'none';
            echo ' default=';
            echo $p->isDefaultValueAvailable() ? var_export($p->getDefaultValue(), true) : 'N/A';
            echo "\n";
        }
        if ($p->getName() === 'contextNode') {
            echo $method, ' contextNode type=', $p->hasType() ? (string) $p->getType() : 'none';
            echo ' default=';
            echo $p->isDefaultValueAvailable() ? var_export($p->getDefaultValue(), true) : 'N/A';
            echo "\n";
        }
    }
}
?>
--EXPECT--
__construct registerNodeNS type=bool default=true
query contextNode type=?DOMNode default=NULL
query registerNodeNS type=bool default=true
evaluate contextNode type=?DOMNode default=NULL
evaluate registerNodeNS type=bool default=true
