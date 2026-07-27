<?php
/**
 * Repro: SimpleXMLElement::xpath named parameter "expression" (#23686).
 * Zend: expression: works; path: rejected.
 * Expected: same behavior on VM.
 */
$xml = simplexml_load_string('<root><a>1</a><b>2</b></root>');

// Positional (baseline)
$positional = $xml->xpath('//a');
echo 'positional: '.count($positional)."\n";

// Named: expression (php-src canonical name)
$named = $xml->xpath(expression: '//a');
echo 'named expression: '.count($named)."\n";

// Reflection check
$rm = new ReflectionMethod('SimpleXMLElement', 'xpath');
$params = $rm->getParameters();
echo 'param name: '.$params[0]->getName()."\n";
