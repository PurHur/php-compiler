<?php
/**
 * #25083 — //a[position()=2] / [last()] / [position()>1] match Zend (XPath 1.0).
 * php-src: ext/dom/xpath.c
 */
$doc = new DOMDocument();
$doc->loadXML('<r><a>1</a><a>2</a><a>3</a></r>');
$xp = new DOMXPath($doc);

$expect = [
    '//a[2]' => ['2'],
    '//a[position()=2]' => ['2'],
    '//a[position()=1]' => ['1'],
    '//a[last()]' => ['3'],
    '//a[position()=last()]' => ['3'],
    '//a[position()>1]' => ['2', '3'],
];

foreach ($expect as $query => $want) {
    $n = $xp->query($query);
    if (false === $n) {
        fwrite(STDERR, "$query: query returned false\n");
        exit(1);
    }
    $got = [];
    foreach ($n as $node) {
        $got[] = $node->textContent;
    }
    if ($got !== $want) {
        fwrite(STDERR, "$query: expected [".implode(',', $want).'] got ['.implode(',', $got)."]\n");
        exit(1);
    }
}

echo "ok\n";
