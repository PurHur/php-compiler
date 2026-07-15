<?php

declare(strict_types=1);

$doc = new DOMDocument();
$flags = LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD;
$doc->loadHTML('<p>hi</p>', $flags);
$out = $doc->saveHTML();
$expected = "<p>hi</p>\n";
if ($out === $expected) {
    echo "ok\n";
    exit(0);
}
echo "fail: got ", var_export($out, true), " expected ", var_export($expected, true), "\n";
exit(1);
