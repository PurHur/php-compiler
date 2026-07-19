<?php

declare(strict_types=1);

/**
 * Repro for #20948 — Dom\ living leaf classes on create* / parse / collections.
 *
 * Run: PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/issue_20948_dom_living_leaf_classes.php
 */
foreach ([
    'Dom\\Text',
    'Dom\\Comment',
    'Dom\\Attr',
    'Dom\\DocumentFragment',
    'Dom\\NamedNodeMap',
    'Dom\\CharacterData',
    'Dom\\CDATASection',
    'Dom\\ProcessingInstruction',
] as $c) {
    echo $c, '=', class_exists($c) ? 'yes' : 'no', "\n";
}

$doc = Dom\XMLDocument::createFromString('<?xml version="1.0"?><root id="x">hi</root>');
echo 'createText=', get_class($doc->createTextNode('t')), "\n";
echo 'firstChild=', get_class($doc->documentElement->firstChild), "\n";
echo 'childNodes=', get_class($doc->documentElement->childNodes), "\n";
echo 'attributes=', get_class($doc->documentElement->attributes), "\n";

$legacy = new DOMDocument();
echo 'legacyText=', get_class($legacy->createTextNode('t')), "\n";
