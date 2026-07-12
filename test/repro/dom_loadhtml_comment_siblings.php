<?php

declare(strict_types=1);

$doc = new DOMDocument();
$doc->loadHTML('<html><body><!--c--><p>x</p></body></html>');
$html = $doc->saveHTML();
$hasComment = false !== strpos($html, '<!--c-->');
$hasParagraph = false !== strpos($html, '<p>x</p>') || false !== strpos($html, '<p>x</p>');
if (!$hasComment) {
    fwrite(STDERR, "fail: missing HTML comment in saveHTML\n");
    exit(1);
}
if (!$hasParagraph) {
    fwrite(STDERR, "fail: missing paragraph in saveHTML: $html\n");
    exit(1);
}
echo 'has_comment: ', $hasComment ? 'true' : 'false', "\n";
echo 'has_paragraph: ', $hasParagraph ? 'true' : 'false', "\n";

$doc2 = new DOMDocument();
$doc2->loadHTML('<html><body>text<!--c--><p>x</p></body></html>');
$html2 = $doc2->saveHTML();
if (false === strpos($html2, 'text') || false === strpos($html2, '<!--c-->') || false === strpos($html2, '<p>x</p>')) {
    fwrite(STDERR, "fail: mixed body content missing: $html2\n");
    exit(1);
}

echo "ok\n";
