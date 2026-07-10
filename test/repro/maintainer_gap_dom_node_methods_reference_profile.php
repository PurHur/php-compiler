<?php

declare(strict_types=1);

$doc = new DOMDocument();
$el = $doc->createElement('x');

$fail = false;
foreach (['contains', 'replaceChildren'] as $method) {
    if (method_exists($el, $method)) {
        fwrite(STDERR, "fail: method_exists(DOMNode, '$method') should be false on reference profile\n");
        $fail = true;
    }
}

if ($fail) {
    exit(1);
}

echo "ok\n";
