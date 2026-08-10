<?php

declare(strict_types=1);

// DOM create/attribute null → TypeError under strict_types (#29985).
$d = new DOMDocument();
$d->loadXML('<r a="1"/>');
$el = $d->documentElement;
$cases = [
    'createElement' => static fn () => $d->createElement(null),
    'createTextNode' => static fn () => $d->createTextNode(null),
    'createAttribute' => static fn () => $d->createAttribute(null),
    'createComment' => static fn () => $d->createComment(null),
    'setAttribute' => static fn () => $el->setAttribute(null, 'v'),
    'getAttribute' => static fn () => $el->getAttribute(null),
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
