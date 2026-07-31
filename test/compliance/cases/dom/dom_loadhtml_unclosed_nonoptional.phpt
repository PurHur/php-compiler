--TEST--
DOMDocument::loadHTML() recovers unclosed non-optional tags (#25988, ext/dom/document.c)
--FILE--
<?php
declare(strict_types=1);

$cases = [
    'div-open' => ['<div id="x">', 'x', 1, 0],
    'nested-open' => ['<div id="a"><span>x', 'a', 1, 1],
    'div-before-body-close' => ['<body><div id="x"></body>', 'x', 1, 0],
    'incomplete-start' => ['<p><unclosed', null, 0, 0],
];

foreach ($cases as $name => [$html, $id, $divs, $spans]) {
    $doc = new DOMDocument();
    $ok = $doc->loadHTML($html);
    echo $name, '=', ($ok ? 'load' : 'fail'),
        ' divs=', $doc->getElementsByTagName('div')->length,
        ' spans=', $doc->getElementsByTagName('span')->length;
    if (null !== $id) {
        echo ' id=', ($doc->getElementById($id) ? 'yes' : 'no');
    } else {
        echo ' unclosed=', $doc->getElementsByTagName('unclosed')->length;
    }
    echo "\n";
}
--EXPECT--
div-open=load divs=1 spans=0 id=yes
nested-open=load divs=1 spans=1 id=yes
div-before-body-close=load divs=1 spans=0 id=yes
incomplete-start=load divs=0 spans=0 unclosed=1
