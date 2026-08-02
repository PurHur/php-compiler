<?php
/** Repro #27039 — AOT DOMDocument::loadXML must not SIGABRT (re-#26757). */
$d = new DOMDocument();
$ok = $d->loadXML('<r><x/></r>');
echo 'ok=', var_export($ok, true), "\n";
echo 'len=', $d->documentElement->childNodes->length, "\n";
echo 'xml=', $d->saveXML($d->documentElement), "\n";
