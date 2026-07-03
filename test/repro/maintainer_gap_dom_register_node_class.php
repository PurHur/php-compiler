<?php

declare(strict_types=1);

class MyElement extends DOMElement {}

$d = new DOMDocument();
if (!method_exists($d, 'registerNodeClass')) {
    fwrite(STDERR, "missing registerNodeClass\n");
    exit(1);
}
$d->registerNodeClass('DOMElement', MyElement::class);
$el = $d->createElement('x');
if (!$el instanceof MyElement) {
    fwrite(STDERR, "not MyElement\n");
    exit(1);
}
$plain = new DOMDocument();
$stock = $plain->createElement('y');
if (!$stock instanceof DOMElement || $stock instanceof MyElement) {
    fwrite(STDERR, "unregistered doc wrong type\n");
    exit(1);
}
echo "ok\n";
