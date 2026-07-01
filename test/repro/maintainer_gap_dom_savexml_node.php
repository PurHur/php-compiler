<?php

declare(strict_types=1);

$doc = new DOMDocument();
$doc->loadXML('<root><a id="1"/><b/></root>');
$a = $doc->getElementsByTagName('a')->item(0);

$subtree = $doc->saveXML($a);
if ('<a id="1"/>' !== $subtree) {
    fwrite(STDERR, "fail: saveXML(\$node) expected '<a id=\"1\"/>', got ".var_export($subtree, true)."\n");
    exit(1);
}

$full = $doc->saveXML();
if (!str_starts_with($full, '<?xml version="1.0"?>')) {
    fwrite(STDERR, "fail: saveXML() should include XML declaration\n");
    exit(1);
}
if (!str_contains($full, '<a id="1"/>')) {
    fwrite(STDERR, "fail: full saveXML should include element attributes\n");
    exit(1);
}

$nullFull = $doc->saveXML(null);
if ($full !== $nullFull) {
    fwrite(STDERR, "fail: saveXML(null) should match saveXML()\n");
    exit(1);
}

echo "ok subtree={$subtree}\n";
