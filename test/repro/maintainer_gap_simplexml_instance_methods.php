<?php
declare(strict_types=1);

$x = simplexml_load_string('<r><a/><b/></r>');
if (false === $x) {
    echo "load_failed\n";
    exit(1);
}

$missing = [];
foreach (['getName', 'children', 'asXML', 'addChild', 'xpath', 'attributes', 'getDocNamespaces', 'getNamespaces', 'registerXPathNamespace'] as $method) {
    if (!method_exists($x, $method)) {
        $missing[] = $method;
    }
}
if ([] !== $missing) {
    echo 'missing='.implode(',', $missing)."\n";
    exit(1);
}

echo $x->getName(), "\n";
$kids = $x->children();
echo count($kids), "\n";
echo $kids[0]->getName(), "\n";
echo trim($x->asXML()), "\n";
$found = $x->xpath('//a');
echo count($found), "\n";
echo $found[0]->getName(), "\n";
$attrs = $x->attributes();
echo count($attrs), "\n";
echo "ok\n";
