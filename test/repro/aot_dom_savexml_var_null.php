<?php
// #33881 — AOT saveXML($n=null) must document-dump like Zend (?DOMNode), not SIGSEGV.
$d = new DOMDocument();
$d->loadXML('<r a="1"><c/></r>');

$n = null;
echo 'var_null=' . str_replace("\n", '\\n', $d->saveXML($n)) . "\n";

echo 'lit_null=' . str_replace("\n", '\\n', $d->saveXML(null)) . "\n";

echo 'omit=' . str_replace("\n", '\\n', $d->saveXML()) . "\n";

$el = $d->documentElement;
$held = $el;
echo 'node=' . str_replace("\n", '\\n', $d->saveXML($held)) . "\n";

echo "DONE\n";
