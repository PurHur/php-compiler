<?php
/**
 * Repro #24247 — AOT user-script appendChild must compile and run (re-#23430 / #18951).
 */
$d = new DOMDocument();
$e = $d->createElement('x');
$d->appendChild($e);
echo "ok\n";
