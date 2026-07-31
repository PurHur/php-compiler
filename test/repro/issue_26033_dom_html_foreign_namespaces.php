<?php

declare(strict_types=1);

/**
 * Repro #26033 — Dom\HTMLDocument HTML5 SVG/MathML foreign-content namespaces.
 *
 * php-src: ext/dom/tests/modern/html/parser/predefined_namespaces.phpt
 * Nested <svg> under <math> stays in the MathML namespace.
 */
if (!class_exists('Dom\\HTMLDocument')) {
    fwrite(STDERR, "fail: Dom\\HTMLDocument missing (need PHP_COMPILER_PROFILE=8.4)\n");
    exit(1);
}

$dom = Dom\HTMLDocument::createFromString(<<<'HTML'
<!DOCTYPE html>
<html>
<head>
    <title>Test</title>
</head>
<body>
    <svg width="100" height="100" viewBox="0 0 4 2">
        <rect id="rectangle" x="10" y="20" width="90" height="60">
        </rect>
    </svg>
    <div>
        <p>foo</p>
    </div>
    <math>
        <!-- svg should be in the mathml namespace -->
        <mtable id="table"><svg></svg></mtable>
    </math>
</body>
</html>
HTML);

$lines = [];
foreach ($dom->body->childNodes as $n) {
    if ($n->nodeType !== XML_ELEMENT_NODE) {
        continue;
    }
    $lines[] = $n->nodeName.' '.($n->namespaceURI ?? '(NONE)');
    foreach ($n->childNodes as $c) {
        if ($c->nodeType !== XML_ELEMENT_NODE) {
            continue;
        }
        $lines[] = '  '.$c->nodeName.' '.($c->namespaceURI ?? '(NONE)');
        foreach ($c->childNodes as $gc) {
            if ($gc->nodeType !== XML_ELEMENT_NODE) {
                continue;
            }
            $lines[] = '    '.$gc->nodeName.' '.($gc->namespaceURI ?? '(NONE)');
        }
    }
}

$expected = [
    'svg http://www.w3.org/2000/svg',
    '  rect http://www.w3.org/2000/svg',
    'DIV http://www.w3.org/1999/xhtml',
    '  P http://www.w3.org/1999/xhtml',
    'math http://www.w3.org/1998/Math/MathML',
    '  mtable http://www.w3.org/1998/Math/MathML',
    '    svg http://www.w3.org/1998/Math/MathML',
];

if ($lines !== $expected) {
    fwrite(STDERR, "fail: namespace matrix mismatch\n");
    fwrite(STDERR, "actual:\n".implode("\n", $lines)."\n");
    fwrite(STDERR, "expected:\n".implode("\n", $expected)."\n");
    exit(1);
}

$svg = null;
foreach ($dom->body->childNodes as $n) {
    if ($n->nodeType === XML_ELEMENT_NODE && 'svg' === $n->nodeName) {
        $svg = $n;
        break;
    }
}
if (null === $svg || !($svg instanceof Dom\Element) || $svg instanceof Dom\HTMLElement) {
    fwrite(STDERR, 'fail: top-level svg must be Dom\\Element, got '.($svg ? get_class($svg) : 'null')."\n");
    exit(1);
}

$div = null;
foreach ($dom->body->childNodes as $n) {
    if ($n->nodeType === XML_ELEMENT_NODE && 'DIV' === $n->nodeName) {
        $div = $n;
        break;
    }
}
if (null === $div || !($div instanceof Dom\HTMLElement)) {
    fwrite(STDERR, 'fail: DIV must be Dom\\HTMLElement, got '.($div ? get_class($div) : 'null')."\n");
    exit(1);
}

echo "ok\n";
