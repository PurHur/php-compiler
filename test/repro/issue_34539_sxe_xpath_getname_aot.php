<?php
/**
 * Repro #34539 — AOT SimpleXMLElement::xpath()[i]->getName() must match Zend.
 * Previously AOT returned "" because tryXpath skipped bakeElementScalars (#27535).
 */
$x = simplexml_load_string('<r><a>1</a><b>2</b></r>');
$r = $x->xpath('/r/b');
echo 'name=', $r[0]->getName(), '|str=', (string) $r[0], '|xml=', $r[0]->asXML(), "\n";
