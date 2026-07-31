<?php
/**
 * Issue #25876 — nested cloneNode(true/false) as arg to parentNode MethodCall.
 * Pre-fix: TypeError deep must be bool, DOMElement given (outer receiver remapped).
 */
$d = new DOMDocument();
$d->loadXML('<r><a>1</a><b>2</b></r>');
$a = $d->getElementsByTagName('a')->item(0);
$b = $d->getElementsByTagName('b')->item(0);
$a->parentNode->replaceChild($b->cloneNode(true), $a);
echo $d->C14N(), "\n";
