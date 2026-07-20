<?php
declare(strict_types=1);
/**
 * AOT repro: compile-time foldable namespace-uri() (#21238).
 */
$d = new DOMDocument();
$d->loadXML('<r xmlns:x="urn:x" xmlns="urn:def"><x:a/><c/></r>');
$xp = new DOMXPath($d);
echo $xp->evaluate('namespace-uri(//x:a)'), "\n";
echo $xp->evaluate('namespace-uri(/*)'), "\n";
