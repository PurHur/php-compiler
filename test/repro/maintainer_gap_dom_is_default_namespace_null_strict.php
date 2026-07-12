<?php

declare(strict_types=1);

// Strict caller must TypeError on null (#18215, re-#14598).
$doc = new DOMDocument();
$doc->loadXML('<root xmlns="http://example.com"/>');
$root = $doc->documentElement;
try {
    $root->isDefaultNamespace(null);
    echo "no_throw\n";
    exit(1);
} catch (TypeError $e) {
    echo "strict_type_error\n";
}
