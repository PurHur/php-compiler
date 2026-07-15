<?php

declare(strict_types=1);

$doc = new DOMDocument();
$flags = LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD;
if (!$doc->loadHTML('<p>hi</p>', $flags)) {
    echo "load_failed\n";
    exit(1);
}
$html = $doc->saveHTML();
if (str_contains($html, 'DOCTYPE')) {
    echo "doctype_injected:".$html."\n";
    exit(1);
}
if (trim($html) !== '<p>hi</p>') {
    echo "bad_html:".$html."\n";
    exit(1);
}
echo "ok\n";
