<?php
/**
 * Repro #27535 — AOT SimpleXMLElement::children() foreach + getName
 * must match Zend/VM/JIT (no segfault).
 */
$xml = simplexml_load_string("<r><a>1</a><b>2</b></r>");
foreach ($xml->children() as $c) {
    echo $c->getName(), ":", (string) $c, ";";
}
echo "\n";
