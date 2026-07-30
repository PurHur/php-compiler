<?php

declare(strict_types=1);

// Inline PropertyFetch + LIBXML_* options — must not TypeError (#25292).
$d = new DOMDocument();
$d->loadXML('<r><a/></r>');
$out = $d->saveXML($d->documentElement, LIBXML_NOEMPTYTAG);
echo $out, "\n";
if (!str_contains($out, '<a></a>')) {
    fwrite(STDERR, "fail: expected expanded empty element, got: {$out}\n");
    exit(1);
}
echo "ok\n";
