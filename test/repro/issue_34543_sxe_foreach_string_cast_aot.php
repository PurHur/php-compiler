<?php
/**
 * Repro #34543 — AOT SimpleXMLElement foreach (string) cast SIGSEGV (re-#27535).
 * children() and attributes() each match Zend; cast must not segfault.
 */
$xml = simplexml_load_string("<r><a>1</a><b>2</b></r>");
foreach ($xml->children() as $c) {
    echo $c->getName(), ":", (string) $c, ";";
}
echo "\n";

$xml2 = simplexml_load_string("<r a=\"1\" b=\"2\"/>");
foreach ($xml2->attributes() as $k => $v) {
    echo $k, ":", (string) $v, ";";
}
echo "\n";
