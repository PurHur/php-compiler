<?php
/**
 * Repro: AOT SimpleXMLElement::xpath() absolute path (#26911).
 * Zend/VM/JIT print 2:1; previously AOT failed with undefined simplemxml_element::xpath().
 */
$x = simplexml_load_string("<r><a>1</a><a>2</a></r>");
$n = $x->xpath("/r/a");
echo count($n), ":", (string)$n[0], "\n";
