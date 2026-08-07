<?php

declare(strict_types=1);

/**
 * #28605 — AOT fresh getElementsByTagName()->length must keep live appendChild increments
 * (held list already did after #19208/#28509).
 */
$d = new DOMDocument();
$d->loadXML('<r/>');
$held = $d->getElementsByTagName('a');
echo 'held_before=', $held->length, "\n";
$d->documentElement->appendChild($d->createElement('a'));
echo 'held_after=', $held->length, "\n";
echo 'fresh_after=', $d->getElementsByTagName('a')->length, "\n";

$d2 = new DOMDocument();
$d2->loadXML('<r><a/></r>');
echo 'seed=', $d2->getElementsByTagName('a')->length, "\n";
$d2->documentElement->appendChild($d2->createElement('a'));
echo 'seed_fresh=', $d2->getElementsByTagName('a')->length, "\n";
