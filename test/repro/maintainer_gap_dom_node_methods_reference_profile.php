<?php

declare(strict_types=1);

// Requires PHP_COMPILER_PROFILE=8.2 before VM startup (class methods register at boot).
// Default 8.4.0-dev enables forward DOM APIs (#18608); this repro pins Zend 8.2 phantom gate.

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
