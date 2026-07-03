<?php
declare(strict_types=1);

$doc = new DOMDocument();
$impl = $doc->implementation;
if (null === $impl) {
    fwrite(STDERR, "FAIL: DOMDocument::\$implementation is null\n");
    exit(1);
}
if (!$impl->hasFeature('XML', '2.0')) {
    fwrite(STDERR, "FAIL: implementation->hasFeature(XML, 2.0) returned false\n");
    exit(1);
}
echo "OK\n";
