<?php

declare(strict_types=1);

$d = new DOMDocument();

$checks = [
    'loadXML' => method_exists($d, 'loadXML'),
    'createElement' => method_exists($d, 'createElement'),
    'appendChild' => method_exists($d, 'appendChild'),
    'saveXML' => method_exists($d, 'saveXML'),
];

foreach ($checks as $name => $ok) {
    if (!$ok) {
        echo "fail: missing method {$name}\n";
        exit(1);
    }
}

if (!$d->loadXML('<root/>')) {
    echo "fail: loadXML returned false\n";
    exit(1);
}

$rootName = $d->documentElement->nodeName ?? '';
if ('root' !== $rootName) {
    echo "fail: documentElement nodeName={$rootName}\n";
    exit(1);
}

$d2 = new DOMDocument();
$item = $d2->createElement('item');
$d2->appendChild($item);
$created = $d2->documentElement->nodeName ?? '';
if ('item' !== $created) {
    echo "fail: createElement/appendChild nodeName={$created}\n";
    exit(1);
}

echo "ok\n";
