<?php
/** Repro #33733: AOT isEqualNode/compareDocumentPosition variable-null must not SIGSEGV. */
error_reporting(E_ALL);
$doc = new DOMDocument();
$doc->loadXML('<r><a id="x"/></r>');
$el = $doc->documentElement;
$n = null;
echo 'eq_var=', (int) $el->isEqualNode($n), "\n";
$miss = $doc->getElementById('nope');
echo 'eq_id=', (int) $el->isEqualNode($miss), "\n";
try {
    $el->compareDocumentPosition($n);
    echo "cdp=fail\n";
} catch (Throwable $ex) {
    echo 'cdp=', get_class($ex), "\n";
}
try {
    $el->compareDocumentPosition($miss);
    echo "cdp_id=fail\n";
} catch (Throwable $ex) {
    echo 'cdp_id=', get_class($ex), "\n";
}
