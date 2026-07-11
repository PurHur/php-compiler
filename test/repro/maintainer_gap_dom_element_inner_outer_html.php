<?php

declare(strict_types=1);

$dom = new DOMDocument();
$dom->loadHTML('<div><b>hi</b></div>');
$el = $dom->getElementsByTagName('div')->item(0);
if (!method_exists($el, 'getInnerHTML')) {
    echo "missing_inner\n";
    exit(1);
}
if (!method_exists($el, 'getOuterHTML')) {
    echo "missing_outer\n";
    exit(1);
}
echo $el->getInnerHTML(), "\n";
echo $el->getOuterHTML(), "\n";
echo "ok\n";
