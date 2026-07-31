<?php

declare(strict_types=1);

// #25988 — loadHTML auto-closes non-optional tags at EOF / before ancestor end tags.
$cases = [
    'div-open' => ['<div id="x">', 'x', 1, 0],
    'nested-open' => ['<div id="a"><span>x', 'a', 1, 1],
    'div-before-body-close' => ['<body><div id="x"></body>', 'x', 1, 0],
    'incomplete-start' => ['<p><unclosed', null, 0, 0],
];

foreach ($cases as $name => [$html, $id, $divs, $spans]) {
    $doc = new DOMDocument();
    $ok = @$doc->loadHTML($html);
    if (true !== $ok) {
        echo "fail: {$name} loadHTML returned false\n";
        exit(1);
    }
    $gotDivs = $doc->getElementsByTagName('div')->length;
    $gotSpans = $doc->getElementsByTagName('span')->length;
    if ($gotDivs !== $divs || $gotSpans !== $spans) {
        echo "fail: {$name} divs={$gotDivs} spans={$gotSpans} expected {$divs}/{$spans}\n";
        exit(1);
    }
    if (null !== $id) {
        $el = $doc->getElementById($id);
        if (null === $el) {
            echo "fail: {$name} getElementById({$id}) null\n";
            exit(1);
        }
    } else {
        if (0 === $doc->getElementsByTagName('unclosed')->length) {
            echo "fail: {$name} expected recovered <unclosed> element\n";
            exit(1);
        }
    }
    echo "{$name}=ok\n";
}

echo "ok\n";
