<?php
/**
 * Repro: AOT SimpleXMLElement::xpath() count under is_array ternary (#27413).
 * Zend/VM/JIT print 1|y; previously AOT folded count($n) via lastTree → 0|y.
 */
$xml = simplexml_load_string('<r><a id="1">x</a><a id="2">y</a></r>');
$n = $xml->xpath('//a[@id="2"]');
echo is_array($n) ? count($n) : 'fail', '|', (string) $n[0], "\n";
