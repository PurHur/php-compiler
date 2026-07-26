<?php

declare(strict_types=1);

// #22734 — addChild must reuse in-scope ns (no redundant xmlns on child).
$x = new SimpleXMLElement('<r xmlns:ns="urn:x"><ns:keep/></r>');
$x->addChild('ns:item', 'v', 'urn:x');
echo str_replace("\n", '', $x->asXML()), "\n";

$y = new SimpleXMLElement('<r xmlns="urn:default"><keep/></r>');
$y->addChild('item', 'v', 'urn:default');
echo str_replace("\n", '', $y->asXML()), "\n";

// Remap to existing prefix for the same URI (xmlSearchNsByHref).
$z = new SimpleXMLElement('<r xmlns:other="urn:x"/>');
$z->addChild('ns:item', 'v', 'urn:x');
echo str_replace("\n", '', $z->asXML()), "\n";
