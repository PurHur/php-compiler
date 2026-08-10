<?php

declare(strict_types=1);

// loadXML/loadHTML + DOMXPath query/evaluate null → TypeError under strict_types (#30041).
$d = new DOMDocument();
$d->loadXML('<r/>');
$xp = new DOMXPath($d);
$cases = [
    'loadXML' => static function () {
        $doc = new DOMDocument();
        return $doc->loadXML(null);
    },
    'loadHTML' => static function () {
        $doc = new DOMDocument();
        return $doc->loadHTML(null);
    },
    'query' => static fn () => $xp->query(null),
    'evaluate' => static fn () => $xp->evaluate(null),
];
foreach ($cases as $name => $fn) {
    try {
        $fn();
        echo $name, "=fail:no_throw\n";
        exit(1);
    } catch (TypeError $e) {
        echo $name, '=ok:', $e->getMessage(), "\n";
    }
}
