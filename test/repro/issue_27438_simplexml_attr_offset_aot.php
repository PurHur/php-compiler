<?php
/**
 * Repro #27438 — AOT SimpleXMLElement attribute offsetGet + string cast.
 * Zend/VM/JIT print '1' / '2'; thin AOT previously segfaulted (exit 139).
 */
$x = simplexml_load_string("<r a=\"1\"><c>2</c></r>");
var_export((string) $x['a']);
echo "\n";
var_export((string) $x->c);
echo "\n";
