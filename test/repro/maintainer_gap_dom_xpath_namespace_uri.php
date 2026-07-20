<?php
/**
 * Repro: DOMXPath::evaluate('namespace-uri(…)') empty while $el->namespaceURI ok (#21238).
 * php-src: ext/dom/xpath.c — XPath 1.0 namespace-uri().
 *
 * Run: php bin/vm.php test/repro/maintainer_gap_dom_xpath_namespace_uri.php
 */
$d = new DOMDocument();
$d->loadXML('<r xmlns:x="urn:x" xmlns="urn:def"><x:a/><c/></r>');
$xp = new DOMXPath($d);
$xp->registerNamespace('x', 'urn:x');
$xp->registerNamespace('d', 'urn:def');

$a = $d->getElementsByTagName('a')->item(0);
echo 'el_ns=', var_export($a->namespaceURI, true), "\n";
echo 'nsuri_xa=', var_export($xp->evaluate('namespace-uri(//x:a)'), true), "\n";
echo 'nsuri_root=', var_export($xp->evaluate('namespace-uri(/*)'), true), "\n";
echo 'nsuri_c=', var_export($xp->evaluate('namespace-uri(//d:c)'), true), "\n";
echo 'local=', var_export($xp->evaluate('local-name(//x:a)'), true), "\n";
echo 'name=', var_export($xp->evaluate('name(//x:a)'), true), "\n";
