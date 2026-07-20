<?php
/**
 * DOMNodeList / DOMNamedNodeMap must implement IteratorAggregate + getIterator()
 * (php-src ext/dom/php_dom.stub.php) — not Iterator directly.
 */
$doc = new DOMDocument();
$doc->loadXML('<r a="1"><b/></r>');
$nl = $doc->getElementsByTagName('*');
$map = $doc->documentElement->attributes;

$fail = 0;
foreach (
    [
        'NodeList IteratorAggregate' => $nl instanceof IteratorAggregate,
        'NodeList not Iterator' => !($nl instanceof Iterator),
        'NodeList getIterator' => method_exists($nl, 'getIterator'),
        'NamedNodeMap IteratorAggregate' => $map instanceof IteratorAggregate,
        'NamedNodeMap not Iterator' => !($map instanceof Iterator),
        'NamedNodeMap getIterator' => method_exists($map, 'getIterator'),
    ] as $label => $ok
) {
    if (!$ok) {
        echo "FAIL $label\n";
        $fail++;
    } else {
        echo "OK $label\n";
    }
}

$it = $nl->getIterator();
echo 'NL_it_class=', get_class($it), "\n";
$n = 0;
foreach ($nl as $k => $node) {
    echo "nl[$k]=", $node->nodeName, "\n";
    $n++;
}
if ($n !== $nl->length) {
    echo "FAIL foreach_count=$n length={$nl->length}\n";
    $fail++;
}

$mit = $map->getIterator();
echo 'Map_it_class=', get_class($mit), "\n";
foreach ($map as $k => $attr) {
    echo "map[$k]=", $attr->name, "\n";
    if (!is_string($k)) {
        echo "FAIL map key type ", gettype($k), "\n";
        $fail++;
    }
}

echo $fail === 0 ? "OK\n" : "FAIL count=$fail\n";
exit($fail === 0 ? 0 : 1);
