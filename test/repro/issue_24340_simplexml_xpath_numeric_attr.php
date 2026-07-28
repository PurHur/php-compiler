<?php
/**
 * Repro #24340 — SimpleXMLElement::xpath() [@attr=N] unquoted numeric equality
 * (XPath 1.0 / php-src ext/simplexml/sxe.c; peer DOM #24333).
 */
declare(strict_types=1);
$x = simplexml_load_string('<r><a id="1">x</a><a id="2">y</a><a id="1.0">z</a><a id="01">w</a></r>');
$quoted = $x->xpath('//a[@id="1"]');
$numeric = $x->xpath('//a[@id=1]');
echo 'quoted=', is_array($quoted) ? count($quoted) : 'false', "\n";
echo 'numeric=', is_array($numeric) ? count($numeric) : 'false', "\n";
echo 'numeric0=', (is_array($numeric) && isset($numeric[0])) ? (string) $numeric[0] : 'n/a', "\n";
