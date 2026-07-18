<?php

declare(strict_types=1);

$doc = new DOMDocument();
$doc->loadHTML('<p>a<br>b</p>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
$br = $doc->getElementsByTagName('br')->item(0);
if (null === $br || '<br>' !== $doc->saveHTML($br)) {
    echo 'fail: html void br got [' . ($br ? $doc->saveHTML($br) : 'null') . "]\n";
    exit(1);
}
if ("<p>a<br>b</p>\n" !== $doc->saveHTML()) {
    echo 'fail: html full got [' . $doc->saveHTML() . "]\n";
    exit(1);
}

$doc2 = new DOMDocument();
$doc2->loadHTML('<div></div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
$div = $doc2->getElementsByTagName('div')->item(0);
if (null === $div || '<div></div>' !== $doc2->saveHTML($div)) {
    echo 'fail: html empty div got [' . ($div ? $doc2->saveHTML($div) : 'null') . "]\n";
    exit(1);
}

$imgDoc = new DOMDocument();
$imgDoc->loadHTML('<img src="x">', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
$img = $imgDoc->getElementsByTagName('img')->item(0);
if (null === $img || '<img src="x">' !== $imgDoc->saveHTML($img)) {
    echo 'fail: html img got [' . ($img ? $imgDoc->saveHTML($img) : 'null') . "]\n";
    exit(1);
}

$doc3 = new DOMDocument();
$doc3->loadXML('<root><a/><br/></root>');
$out = trim($doc3->saveHTML());
if ('<root><a></a><br></root>' !== $out) {
    echo "fail: xml→saveHTML got [{$out}]\n";
    exit(1);
}

echo "ok savehtml void+empty\n";
